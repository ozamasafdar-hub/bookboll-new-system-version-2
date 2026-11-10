<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The customer inbox: one-to-one direct messages, plus a row per community group
 * chat so both live in the same list.
 *
 * Participants are addressed by users.uuid — the same identifier the community
 * chat, follows and street-cred tables use. A DM's conversation_key is the two
 * uuids sorted and joined with "__"; a group's is "community__{communityUuid}",
 * which is also how a group row is told apart from a direct one.
 */
class CustomerMessageService
{
    public const GROUP_PREFIX = 'community__';

    public function __construct(protected OneSignalService $push) {}

    /** Stable key for a pair, whichever way round the two are given. */
    public function conversationKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode('__', $pair);
    }

    /**
     * The whole inbox, newest first: every peer this customer has exchanged
     * messages with, and every community they belong to.
     */
    public function inbox(User $user)
    {
        if (! $user->uuid) {
            return sendSuccess('Conversations', ['conversations' => []]);
        }

        $conversations = $this->directConversations($user)
            ->merge($this->groupConversations($user))
            ->sortByDesc(fn ($c) => $c['lastAt'] ?? '')
            ->values()
            ->all();

        return sendSuccess('Conversations', ['conversations' => $conversations]);
    }

    /** One row per peer: their last message and how many of theirs are unread. */
    protected function directConversations(User $user): Collection
    {
        $rows = CustomerMessage::query()
            ->whereNotNull('recipient_id')
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->uuid)
                    ->orWhere('recipient_id', $user->uuid);
            })
            // created_at alone is ambiguous for messages sent in the same second —
            // id breaks the tie so "last message" is genuinely the last one.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $byPeer = [];
        foreach ($rows as $m) {
            $peer = $m->sender_id === $user->uuid ? $m->recipient_id : $m->sender_id;
            if (! $peer) {
                continue;
            }
            if (! isset($byPeer[$peer])) {
                $byPeer[$peer] = ['last' => $m, 'unread' => 0];
            }
            if ($m->recipient_id === $user->uuid && ! $m->read_at) {
                $byPeer[$peer]['unread']++;
            }
        }

        if (! $byPeer) {
            return collect();
        }

        $people = User::whereIn('uuid', array_keys($byPeer))
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        return collect($byPeer)->map(function ($row, $peerId) use ($people, $user) {
            $p = $people->get($peerId);
            $last = $row['last'];

            return [
                'group'    => false,
                'peerId'   => $peerId,
                'peer'     => $this->peerPayload($peerId, $p),
                'lastBody' => $last->body,
                'lastAt'   => optional($last->created_at)?->toIso8601String(),
                'lastMine' => $last->sender_id === $user->uuid,
                'unread'   => $row['unread'],
            ];
        })->values();
    }

    /**
     * One row per community the customer belongs to — shown even before anyone
     * has posted, so the group chat is reachable from the inbox on day one.
     */
    protected function groupConversations(User $user): Collection
    {
        $memberships = CommunityMember::where('customer_id', $user->uuid)
            ->where('status', 'accepted')
            ->get()
            ->keyBy('community_id');

        if ($memberships->isEmpty()) {
            return collect();
        }

        $communities = Community::whereIn('id', $memberships->keys())
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get();

        if ($communities->isEmpty()) {
            return collect();
        }

        $keys = $communities->map(fn (Community $c) => self::GROUP_PREFIX.$c->uuid);

        // Newest message per group key, in one pass over the recent window.
        $lastByKey = CustomerMessage::whereIn('conversation_key', $keys)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->groupBy('conversation_key')
            ->map(fn ($group) => $group->first());

        return $communities->map(function (Community $c) use ($memberships, $lastByKey, $user) {
            $key = self::GROUP_PREFIX.$c->uuid;
            $last = $lastByKey->get($key);
            $membership = $memberships->get($c->id);

            return [
                'group'       => true,
                'peerId'      => $key,
                'communityId' => $c->uuid,
                'peer'        => [
                    'id'         => $c->uuid,
                    'full_name'  => $c->name ?? 'Community',
                    'avatar_url' => $c->avatar_image ?: ($c->cover_image ?: ''),
                ],
                'lastBody'    => $last?->body ?: 'Community group chat',
                'lastAt'      => optional($last?->created_at ?: $c->created_at)?->toIso8601String(),
                'lastMine'    => (bool) $last && $last->sender_id === $user->uuid,
                'unread'      => $this->groupUnread($key, $user->uuid, $membership?->last_read_at),
            ];
        })->values();
    }

    protected function groupUnread(string $key, string $meUuid, $lastReadAt): int
    {
        $q = CustomerMessage::where('conversation_key', $key)
            ->where('sender_id', '!=', $meUuid);

        if ($lastReadAt) {
            $q->where('created_at', '>', $lastReadAt);
        }

        return (int) $q->count();
    }

    /**
     * A single direct thread, oldest first, with the peer so the header can
     * render without a second request.
     */
    public function thread(User $user, string $peerId, int $limit = 200)
    {
        if (! $user->uuid || $peerId === '') {
            return sendSuccess('Messages', ['messages' => [], 'peer' => null]);
        }

        $rows = CustomerMessage::where('conversation_key', $this->conversationKey($user->uuid, $peerId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $peer = User::where('uuid', $peerId)->first(['uuid', 'name', 'image']);

        return sendSuccess('Messages', [
            'messages' => $rows->map(fn (CustomerMessage $m) => $this->mapMessage($m))->values()->all(),
            'peer'     => $this->peerPayload($peerId, $peer),
        ]);
    }

    public function send(User $user, string $recipientId, string $body)
    {
        $clean = trim($body);
        if (! $user->uuid || $recipientId === '' || $clean === '') {
            return sendError('Empty message');
        }
        if ($recipientId === $user->uuid) {
            return sendError("You can't message yourself.");
        }

        $recipient = User::where('uuid', $recipientId)->first();
        if (! $recipient) {
            return sendError('That person could not be found.', [], 404);
        }

        $message = CustomerMessage::create([
            'conversation_key' => $this->conversationKey($user->uuid, $recipientId),
            'sender_id'        => $user->uuid,
            'recipient_id'     => $recipientId,
            'body'             => $clean,
        ]);

        $this->pushDirectMessage($user, $recipient, $message);

        return sendSuccess('Message sent', ['message' => $this->mapMessage($message)]);
    }

    /**
     * Tell the recipient a direct message arrived.
     *
     * Same shape as the community pushes: title = who, body = what they said,
     * and a `route` the mobile app opens straight into the thread. Synchronous
     * for the same reason as CommunityNotifier (one recipient, one request),
     * and it never throws — a push failure must not fail the send.
     */
    protected function pushDirectMessage(User $sender, User $recipient, CustomerMessage $message): void
    {
        try {
            $name = trim((string) $sender->name);
            $flat = trim((string) preg_replace('/\s+/', ' ', (string) $message->body));

            $this->push->sendToUsers(
                [(int) $recipient->id],
                $name !== '' ? $name : 'New message',
                Str::limit($flat, 90, '…'),
                [
                    'type'       => 'message',
                    'peer_id'    => $sender->uuid,
                    'peer_name'  => $name,
                    'message_id' => $message->uuid,
                    'route'      => '/messages/'.$sender->uuid,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Direct message push failed', [
                'recipient_id' => $recipient->id,
                'message'      => $e->getMessage(),
            ]);
        }
    }

    /** Mark everything the peer sent me in this thread as read. */
    public function markRead(User $user, string $peerId)
    {
        if (! $user->uuid || $peerId === '') {
            return sendSuccess('Marked read', ['ok' => true]);
        }

        CustomerMessage::where('conversation_key', $this->conversationKey($user->uuid, $peerId))
            ->where('recipient_id', $user->uuid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return sendSuccess('Marked read', ['ok' => true]);
    }

    protected function mapMessage(CustomerMessage $m): array
    {
        return [
            'id'               => $m->uuid,
            'conversation_key' => $m->conversation_key,
            'sender_id'        => $m->sender_id,
            'recipient_id'     => $m->recipient_id,
            'body'             => $m->body,
            'created_at'       => optional($m->created_at)?->toIso8601String(),
            'read_at'          => optional($m->read_at)?->toIso8601String(),
        ];
    }

    protected function peerPayload(string $peerId, ?User $p): array
    {
        return [
            'id'         => $peerId,
            'full_name'  => $p?->name ?: 'Player',
            'avatar_url' => $p?->image ?: '',
        ];
    }
}
