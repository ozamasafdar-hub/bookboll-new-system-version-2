<?php

namespace App\Services;

use App\Models\{Community, CommunityMember, CustomerMessage, GameJoinRequest, HostedGame, Setting, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Group membership, validated text mentions, and durable unread-alert receipts. */
class ChatService
{
    public const SETTINGS_KEY = 'marketplace_chat_alerts';
    public const KINDS = ['direct', 'group', 'mention'];

    public function settings(): array
    {
        $saved = json_decode(Setting::where('key', self::SETTINGS_KEY)->value('value') ?? '{}', true) ?: [];
        $out = [];
        foreach (self::KINDS as $kind) {
            foreach (['email', 'whatsapp'] as $channel) $out[$kind][$channel] = (bool) ($saved[$kind][$channel] ?? true);
        }
        return $out;
    }

    public function preferences(User $user): array
    {
        $saved = $user->notification_preferences['chat'] ?? [];
        return ['email' => (bool) ($saved['email'] ?? true), 'whatsapp' => (bool) ($saved['whatsapp'] ?? false)];
    }

    /** Never exposes pending applicants, emails or phone numbers. */
    public function group(string $key): ?array
    {
        if (str_starts_with($key, 'community__')) {
            $c = Community::where('uuid', substr($key, 11))->where(fn ($q) => $q->whereNull('moderation_status')->orWhere('moderation_status', 'active'))->first();
            if (!$c) return null;
            return ['title' => $c->name, 'route' => '/messages/'.$key, 'ids' => CommunityMember::where('community_id', $c->id)->where('status', 'accepted')->pluck('customer_id')->push($c->owner_customer_id)->filter()->unique()->values()->all()];
        }
        if (str_starts_with($key, 'meet__')) {
            $g = HostedGame::where('uuid', substr($key, 6))->where('status', '!=', 'cancelled')->first();
            if (!$g) return null;
            return ['title' => $g->title ?: 'Event chat', 'route' => '/games/'.$g->uuid.'?chat=1', 'ids' => GameJoinRequest::where('game_id', $g->id)->where('status', 'accepted')->pluck('joiner_customer_id')->push($g->host_customer_id)->filter()->unique()->values()->all()];
        }
        return null;
    }

    public function requireGroup(User $user, string $key): array
    {
        $group = $this->group($key);
        abort_unless($group && in_array($user->uuid, $group['ids'], true), 403, 'Only accepted participants can access this chat.');
        return $group;
    }

    /** Offsets use UTF-16 code units, matching browser selectionStart (including emoji). */
    public function mentions(User $sender, string $key, string $body, array $input): array
    {
        if (!$input) return [];
        $group = $this->requireGroup($sender, $key);
        $people = User::whereIn('uuid', $group['ids'])->get(['uuid', 'name'])->keyBy('uuid');
        $encoded = mb_convert_encoding($body, 'UTF-16LE', 'UTF-8');
        $out = []; $end = 0;
        usort($input, fn ($a, $b) => ($a['start'] ?? -1) <=> ($b['start'] ?? -1));
        foreach ($input as $tag) {
            $person = $people->get($tag['user_id'] ?? '');
            $start = $tag['start'] ?? -1; $length = $tag['length'] ?? 0;
            $label = '@'.trim((string) $person?->name);
            $expected = mb_convert_encoding($label, 'UTF-16LE', 'UTF-8');
            if (!$person || $start < $end || $length < 2 || $length * 2 !== strlen($expected) || substr($encoded, $start * 2, $length * 2) !== $expected) {
                throw ValidationException::withMessages(['mentions' => 'A tagged participant or their name changed. Please choose them again using @.']);
            }
            $out[] = ['user_id' => $person->uuid, 'start' => $start, 'length' => $length, 'label' => $label];
            $end = $start + $length;
        }
        return $out;
    }

    /** Called in the same transaction as message creation. No external sends here. */
    public function queue(CustomerMessage $message): void
    {
        $ids = $message->recipient_id ? [$message->recipient_id] : ($this->group($message->conversation_key)['ids'] ?? []);
        $settings = $this->settings();
        $tagged = array_column($message->mentions ?? [], 'user_id');
        User::whereIn('uuid', $ids)->where('uuid', '!=', $message->sender_id)->where('status', User::ACTIVE)->chunkById(200, function ($users) use ($message, $settings, $tagged) {
            $rows = [];
            foreach ($users as $user) {
                $kind = $message->recipient_id ? 'direct' : (in_array($user->uuid, $tagged, true) ? 'mention' : 'group');
                foreach (['email', 'whatsapp'] as $channel) {
                    if (!$settings[$kind][$channel] || !$this->preferences($user)[$channel]) continue;
                    $rows[] = ['message_id' => $message->id, 'user_id' => $user->id, 'conversation_key' => $message->conversation_key, 'kind' => $kind, 'channel' => $channel, 'status' => 'pending', 'due_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now()];
                }
            }
            if ($rows) DB::table('chat_alerts')->insertOrIgnore($rows);
        });
    }

    public function markRead(User $user, string $key, string $through): void
    {
        $this->requireGroup($user, $key);
        $message = CustomerMessage::where('conversation_key', $key)->where('uuid', $through)->first();
        if (!$message) return;
        $id = $message->id;
        DB::table('chat_reads')->insertOrIgnore(['user_id' => $user->id, 'conversation_key' => $key, 'last_message_id' => 0]);
        DB::table('chat_reads')->where('user_id', $user->id)->where('conversation_key', $key)->where('last_message_id', '<', $id)->update(['last_message_id' => $id]);
        if (str_starts_with($key, 'community__')) {
            $communityId = Community::where('uuid', substr($key, 11))->value('id');
            CommunityMember::where('community_id', $communityId)->where('customer_id', $user->uuid)
                ->where(fn ($q) => $q->whereNull('last_read_at')->orWhere('last_read_at', '<', $message->created_at))
                ->update(['last_read_at' => $message->created_at]);
        }
    }

    public function unread(User $user, CustomerMessage $message): bool
    {
        if ($message->sender_id === $user->uuid) return false;
        if ($message->recipient_id) return $message->recipient_id === $user->uuid && !$message->read_at;
        $group = $this->group($message->conversation_key);
        if (!$group || !in_array($user->uuid, $group['ids'], true)) return false;
        $read = DB::table('chat_reads')->where('user_id', $user->id)->where('conversation_key', $message->conversation_key)->value('last_message_id') ?? 0;
        return $message->id > $read;
    }
}
