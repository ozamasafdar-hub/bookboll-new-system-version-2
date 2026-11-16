<?php
namespace App\Http\Controllers;

use App\Models\{Setting, User};
use App\Services\{ChatService, WhatsappService};
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}
    public function participants(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:200', 'q' => 'nullable|string|max:80']);
        $group = $this->chat->requireGroup($r->user(), $d['key']);
        $q = User::whereIn('uuid', $group['ids'])->where('uuid', '!=', $r->user()->uuid)->where('status', User::ACTIVE);
        if ($d['q'] ?? '') $q->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $d['q']).'%');
        return sendSuccess('Participants', ['participants' => $q->orderBy('name')->orderBy('id')->limit(20)->toBase()->get(['uuid as id', 'name', 'image as avatar_url'])]);
    }
    public function read(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:200', 'through' => 'required|uuid']);
        $this->chat->markRead($r->user(), $d['key'], $d['through']);
        return sendSuccess('Marked read', []);
    }
    public function preferences(Request $r)
    {
        if ($r->isMethod('put')) {
            $d = $r->validate(['email' => 'required|boolean', 'whatsapp' => 'required|boolean']);
            $r->user()->update(['notification_preferences' => array_replace($r->user()->notification_preferences ?? [], ['chat' => $d])]);
        }
        return sendSuccess('Chat preferences', ['preferences' => $this->chat->preferences($r->user())]);
    }
    public function settings(Request $r)
    {
        abort_unless($r->user()->hasRole('super_admin') && !AccessScope::isProviderScoped($r->user()) && !AccessScope::ownerUserId($r->user()), 403);
        if ($r->isMethod('put')) {
            $rules = [];
            foreach (ChatService::KINDS as $kind) foreach (['email', 'whatsapp'] as $ch) $rules[$kind.'.'.$ch] = 'required|boolean';
            Setting::updateOrCreate(['key' => ChatService::SETTINGS_KEY], ['value' => json_encode($r->validate($rules))]);
        }
        $sender = app(WhatsappService::class)->platformSender();
        return sendSuccess('Marketplace chat alerts', ['settings' => $this->chat->settings(), 'preview' => (bool) config('preview.enabled'), 'whatsapp_ready' => (bool) ($sender['phone_number_id'] && $sender['token'] && config('services.whatsapp.chat_template')), 'delivery' => DB::table('chat_alerts')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }
}
