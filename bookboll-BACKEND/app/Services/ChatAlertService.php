<?php
namespace App\Services;

use App\Models\{CustomerMessage, User};
use App\Notifications\ChatUnreadNotification;
use Illuminate\Support\Facades\{Cache, DB};

class ChatAlertService
{
    public function __construct(private ChatService $chat) {}

    public function deliver(int $limit = 100): int
    {
        $lock = Cache::lock('marketplace-chat-alert-delivery', 300);
        if (!$lock->get()) return 0;
        try {
            // An interrupted network send has an uncertain outcome: never auto-retry it.
            DB::table('chat_alerts')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'needs_review']);
            $batches = DB::table('chat_alerts')->where('status', 'pending')->where('due_at', '<=', now())
                ->select('user_id', 'conversation_key', 'channel')->groupBy('user_id', 'conversation_key', 'channel')->limit($limit)->get();
            foreach ($batches as $batch) $this->batch($batch);
            return $batches->count();
        } finally { $lock->release(); }
    }

    private function batch(object $batch): void
    {
        $q = fn () => DB::table('chat_alerts')->where('user_id', $batch->user_id)->where('conversation_key', $batch->conversation_key)->where('channel', $batch->channel);
        $rows = $q()->where('status', 'pending')->where('due_at', '<=', now())->orderBy('id')->limit(500)->get();
        $user = User::find($batch->user_id);
        $settings = $this->chat->settings(); $valid = []; $mentioned = false; $last = null;
        $messages = CustomerMessage::whereIn('id', $rows->pluck('message_id'))->get()->keyBy('id');
        foreach ($rows as $row) {
            $message = $messages->get($row->message_id);
            if (!$user || $user->status !== User::ACTIVE || !$message || $message->created_at->lt(now()->subDay()) || !$settings[$row->kind][$row->channel] || !$this->chat->preferences($user)[$row->channel] || !$this->chat->unread($user, $message)) {
                DB::table('chat_alerts')->where('id', $row->id)->update(['status' => 'skipped', 'updated_at' => now()]);
                continue;
            }
            $valid[] = $row->id; $last = $message; $mentioned = $mentioned || $row->kind === 'mention';
        }
        if (!$valid) return;
        $update = fn (array $values) => DB::table('chat_alerts')->whereIn('id', $valid)->update($values + ['updated_at' => now()]);
        $recent = $q()->whereIn('status', ['sent', 'preview', 'needs_review', 'sending'])->where('updated_at', '>', now()->subMinutes(15))->max('updated_at');
        if ($recent) { $update(['due_at' => \Carbon\Carbon::parse($recent)->addMinutes(15)]); return; }
        if (config('preview.enabled')) { $update(['status' => 'preview']); return; }

        $wa = app(WhatsappService::class); $sender = $wa->platformSender();
        if ($batch->channel === 'whatsapp' && (!config('services.whatsapp.chat_template') || !$sender['phone_number_id'] || !$sender['token'])) {
            $update(['due_at' => now()->addMinutes(15)]); return;
        }
        if (($batch->channel === 'email' && !filter_var($user->email, FILTER_VALIDATE_EMAIL)) || ($batch->channel === 'whatsapp' && !WhatsappService::normalizeNumber($user->phone ?? ''))) {
            $update(['status' => 'skipped']); return;
        }
        $group = $last->recipient_id ? null : $this->chat->group($last->conversation_key);
        $title = $group['title'] ?? 'your Bookboll conversation';
        $route = $group['route'] ?? '/messages/'.$last->sender_id;
        $url = rtrim(config('app.frontend_url'), '/').$route;
        // Claim under row locks as well as the scheduler lock: an expired worker
        // lease or manual second command must not send the same batch twice.
        $claimed = DB::transaction(function () use ($valid) {
            $locked = DB::table('chat_alerts')->whereIn('id', $valid)->lockForUpdate()->get();
            if ($locked->contains(fn ($row) => $row->status !== 'pending')) return false;
            DB::table('chat_alerts')->whereIn('id', $valid)->update(['status' => 'sending', 'updated_at' => now()]);
            return true;
        });
        if (!$claimed) return;
        try {
            // Recheck preferences/read state immediately before external delivery.
            if (!$this->chat->preferences($user->fresh())[$batch->channel] || !$this->chat->unread($user, $last)) { $update(['status' => 'skipped']); return; }
            if ($batch->channel === 'email') $user->notifyNow(new ChatUnreadNotification($title, $url, $mentioned));
            else {
                $result = $wa->sendTemplate($sender, ['to' => $user->phone, 'customerId' => $user->id,
                    'template' => ['name' => config('services.whatsapp.chat_template'), 'language' => config('services.whatsapp.chat_language', 'en_US')],
                    'variables' => [$mentioned ? 'an unread mention' : 'unread messages', $title, $url],
                    'bodyText' => 'You have {{1}} in {{2}} on Bookboll. Open your chat: {{3}}']);
                if (!($result['ok'] ?? false)) throw new \RuntimeException('Chat WhatsApp delivery was not confirmed.');
            }
            $update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $update(['status' => 'needs_review']);
            report($e);
        }
    }
}
