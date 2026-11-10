<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use App\Models\CustomerMessage;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every push a community sends, in one place.
 *
 * One method per event. Each decides WHO hears about it (never the person who
 * did it), WHAT they read (title = the community, message = who did what),
 * and the `data` the apps route on (`type`, the community uuid, and a `route`
 * the mobile app opens directly). Delivery goes through OneSignalService,
 * which already splits recipients across the customer and admin apps.
 *
 * Deliberately synchronous: OneSignal takes a whole audience in one request,
 * so even a large community is a single HTTP call, and a chat message that
 * arrived a minute late would be worse than one that cost 200 ms. Nothing in
 * here throws — a failed push must never fail the action that caused it.
 *
 * Audiences:
 *   managers — the owner and accepted admins (join requests, joins, leaves)
 *   members  — every accepted member (posts, chat, meetups)
 *   one person — the member an action was taken on (accepted, declined,
 *                removed, role changed, post or message removed)
 */
class CommunityNotifier
{
    public const T_JOIN_REQUEST     = 'community_join_request';
    public const T_MEMBER_JOINED    = 'community_member_joined';
    public const T_MEMBER_LEFT      = 'community_member_left';
    public const T_REQUEST_ACCEPTED = 'community_request_accepted';
    public const T_REQUEST_DECLINED = 'community_request_declined';
    public const T_MEMBER_REMOVED   = 'community_member_removed';
    public const T_ROLE_CHANGED     = 'community_role_changed';
    public const T_POST             = 'community_post';
    public const T_POST_REMOVED     = 'community_post_removed';
    public const T_CHAT             = 'community_chat';
    public const T_CHAT_REMOVED     = 'community_chat_removed';
    public const T_EVENT            = 'community_event';
    public const T_MEET_CHAT        = 'game_chat';

    public function __construct(protected OneSignalService $push) {}

    // ── Membership ───────────────────────────────────────────────────────────

    /** A private community: someone asked to join — the owner and admins decide. */
    public function joinRequested(Community $community, User $requester): void
    {
        $this->send(
            $this->managerIds($community, [$requester->uuid]),
            $community->name,
            $this->displayName($requester).' wants to join — tap to review',
            $this->data(self::T_JOIN_REQUEST, $community, ['route' => $this->route($community, 'members'), 'customer_id' => $requester->uuid])
        );
    }

    /** An open community: someone joined — the owner and admins hear about it. */
    public function memberJoined(Community $community, User $member): void
    {
        $this->send(
            $this->managerIds($community, [$member->uuid]),
            $community->name,
            $this->displayName($member).' joined the community',
            $this->data(self::T_MEMBER_JOINED, $community, ['route' => $this->route($community, 'members'), 'customer_id' => $member->uuid])
        );
    }

    public function memberLeft(Community $community, User $member): void
    {
        $this->send(
            $this->managerIds($community, [$member->uuid]),
            $community->name,
            $this->displayName($member).' left the community',
            $this->data(self::T_MEMBER_LEFT, $community, ['route' => $this->route($community, 'members'), 'customer_id' => $member->uuid])
        );
    }

    public function requestAccepted(Community $community, CommunityMember $member): void
    {
        $this->send(
            $this->userIdsFor([$member->customer_id]),
            "You're in!",
            'Welcome to '.$community->name.' — say hi in the group chat.',
            $this->data(self::T_REQUEST_ACCEPTED, $community, ['route' => $this->route($community)])
        );
    }

    public function requestDeclined(Community $community, CommunityMember $member): void
    {
        $this->send(
            $this->userIdsFor([$member->customer_id]),
            $community->name,
            "Your request to join wasn't approved this time.",
            $this->data(self::T_REQUEST_DECLINED, $community, ['route' => '/communities'])
        );
    }

    public function memberRemoved(Community $community, CommunityMember $member, User $actor): void
    {
        if ((string) $member->customer_id === (string) $actor->uuid) {
            return;
        }
        $this->send(
            $this->userIdsFor([$member->customer_id]),
            $community->name,
            'You were removed from the community.',
            $this->data(self::T_MEMBER_REMOVED, $community, ['route' => '/communities'])
        );
    }

    public function roleChanged(Community $community, CommunityMember $member, string $role, User $actor): void
    {
        if ((string) $member->customer_id === (string) $actor->uuid) {
            return;
        }
        $message = match ($role) {
            'admin'     => "You're now an admin — you can manage members and settings.",
            'moderator' => "You're now a moderator — you can remove posts and messages, and host meetups.",
            default     => 'Your role was changed to member.',
        };
        $this->send(
            $this->userIdsFor([$member->customer_id]),
            $community->name,
            $message,
            $this->data(self::T_ROLE_CHANGED, $community, ['route' => $this->route($community), 'role' => $role])
        );
    }

    // ── Feed ─────────────────────────────────────────────────────────────────

    public function postCreated(Community $community, CommunityPost $post, User $author): void
    {
        $body = trim((string) $post->body);
        $photos = is_array($post->photos) ? count(array_filter($post->photos)) : 0;
        $what = $body !== ''
            ? $this->snippet($body)
            : ($photos > 1 ? "shared {$photos} photos" : 'shared a photo');

        $this->send(
            $this->memberIds($community, [$author->uuid]),
            $community->name,
            $this->displayName($author).': '.$what,
            $this->data(self::T_POST, $community, ['route' => $this->route($community, 'feed'), 'post_id' => $post->uuid])
        );
    }

    /** A moderator removed someone else's post — the author should know. */
    public function postRemoved(Community $community, CommunityPost $post, User $actor): void
    {
        if ((string) $post->author_id === (string) $actor->uuid) {
            return;
        }
        $this->send(
            $this->userIdsFor([$post->author_id]),
            $community->name,
            'A moderator removed your post.',
            $this->data(self::T_POST_REMOVED, $community, ['route' => $this->route($community, 'feed')])
        );
    }

    // ── Group chat ───────────────────────────────────────────────────────────

    public function chatMessage(Community $community, CustomerMessage $message, User $sender): void
    {
        $this->send(
            $this->memberIds($community, [$sender->uuid]),
            $community->name,
            $this->displayName($sender).': '.$this->snippet((string) $message->body),
            $this->data(self::T_CHAT, $community, ['route' => '/communities/chat/'.$community->uuid, 'message_id' => $message->uuid])
        );
    }

    public function chatMessageRemoved(Community $community, CustomerMessage $message, User $actor): void
    {
        if ((string) $message->sender_id === (string) $actor->uuid) {
            return;
        }
        $this->send(
            $this->userIdsFor([$message->sender_id]),
            $community->name,
            'A moderator removed your message from the group chat.',
            $this->data(self::T_CHAT_REMOVED, $community, ['route' => '/communities/chat/'.$community->uuid])
        );
    }

    // ── Meetups ──────────────────────────────────────────────────────────────

    /** A meetup went live on the community page (hosted here, or attached to it). */
    public function eventPosted(Community $community, HostedGame $game, ?User $host): void
    {
        $when = $game->game_date ? Carbon::parse($game->game_date)->format('D, M j') : '';
        $title = trim((string) ($game->title ?: ($game->sport ? $game->sport.' meetup' : 'a meetup')));
        $hostName = $host ? $this->displayName($host) : trim((string) ($game->host_name ?: 'Someone'));

        $this->send(
            $this->memberIds($community, array_filter([$host?->uuid, $game->host_customer_id])),
            $community->name,
            "{$hostName} is hosting {$title}".($when ? " on {$when}" : '').' — tap to join',
            $this->data(self::T_EVENT, $community, ['route' => $this->route($community, 'events'), 'game_id' => $game->uuid, 'game_type' => $game->game_type ?: 'game'])
        );
    }

    // ── Event chat ───────────────────────────────────────────────────────────

    /**
     * A message in a hosted event's chat (`meet__{uuid}`): the host and every
     * accepted joiner hear it, except whoever wrote it. Not a community event,
     * but the same shape and delivery, so it lives with the other chat push.
     */
    public function meetChatMessage(HostedGame $game, CustomerMessage $message, User $sender): void
    {
        $uuids = GameJoinRequest::where('game_id', $game->id)
            ->where('status', 'accepted')
            ->pluck('joiner_customer_id')
            ->push((string) $game->host_customer_id)
            ->filter()
            ->unique()
            ->diff([(string) $sender->uuid])
            ->values()
            ->all();

        $title = trim((string) ($game->title ?: ($game->sport ? $game->sport.' meetup' : 'Event chat')));

        $this->send(
            $this->userIdsFor($uuids),
            $title,
            $this->displayName($sender).': '.$this->snippet((string) $message->body),
            [
                'type'       => self::T_MEET_CHAT,
                'game_id'    => $game->uuid,
                'game_type'  => $game->game_type ?: 'game',
                'message_id' => $message->uuid,
                'route'      => '/games/chat/'.$game->uuid,
            ]
        );
    }

    // ── Audiences ────────────────────────────────────────────────────────────

    /** users.id of the owner and every accepted admin, minus the actor. */
    protected function managerIds(Community $community, array $excludeUuids = []): array
    {
        $uuids = CommunityMember::where('community_id', $community->id)
            ->where('status', 'accepted')
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('customer_id')
            ->push((string) $community->owner_customer_id)
            ->filter()
            ->unique()
            ->diff(array_map('strval', array_filter($excludeUuids)))
            ->values()
            ->all();

        return $this->userIdsFor($uuids);
    }

    /** users.id of every accepted member (the owner included), minus the actor. */
    protected function memberIds(Community $community, array $excludeUuids = []): array
    {
        $uuids = CommunityMember::where('community_id', $community->id)
            ->where('status', 'accepted')
            ->pluck('customer_id')
            ->push((string) $community->owner_customer_id)
            ->filter()
            ->unique()
            ->diff(array_map('strval', array_filter($excludeUuids)))
            ->values()
            ->all();

        return $this->userIdsFor($uuids);
    }

    /** Community rows address people by users.uuid; OneSignal rows by users.id. */
    protected function userIdsFor(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids))));
        if (! $uuids) {
            return [];
        }

        return User::whereIn('uuid', $uuids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ── Payload helpers ──────────────────────────────────────────────────────

    protected function data(string $type, Community $community, array $extra = []): array
    {
        return array_merge([
            'type'           => $type,
            'community_id'   => $community->uuid,
            'community_name' => $community->name,
        ], $extra);
    }

    protected function route(Community $community, ?string $tab = null): string
    {
        return '/communities/'.$community->uuid.($tab ? '?tab='.$tab : '');
    }

    protected function displayName(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : 'A member';
    }

    protected function snippet(string $text, int $max = 90): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($flat, $max, '…');
    }

    protected function send(array $userIds, string $title, string $message, array $data): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) {
            return;
        }

        try {
            $this->push->sendToUsers($userIds, $title, $message, $data);
        } catch (\Throwable $e) {
            Log::error('Community push failed', [
                'type'    => $data['type'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
