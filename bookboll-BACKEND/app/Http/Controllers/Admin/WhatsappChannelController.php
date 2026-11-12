<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsappQueueService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;

/**
 * The WhatsApp settings page and the marketing composer's WhatsApp side — the
 * Laravel home of what used to be the `send-whatsapp` edge function.
 *
 * Every action here sends from the CALLER's own number, resolved server-side. The
 * separate /admin/notifications/whatsapp route is the automated-notification path and
 * always uses Bookboll's number instead.
 */
class WhatsappChannelController extends Controller
{
    public function __construct(
        protected WhatsappService $whatsapp,
        protected WhatsappQueueService $queue,
    ) {}

    public function showConfig(Request $request)
    {
        $sender = $this->whatsapp->senderForCaller($request->user());
        $config = $this->whatsapp->configForCaller($request->user());

        return sendSuccess('WhatsApp settings', [
            'config'       => $config,
            'is_provider'  => $sender['is_provider'],
            'configured'   => WhatsappService::isConfigured($config, $sender['is_provider']),
            'webhook_url'  => url('/api/whatsapp/webhook'),
        ]);
    }

    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'enabled'         => 'nullable|boolean',
            'app_id'          => 'nullable|string|max:191',
            'app_secret'      => 'nullable|string|max:255',
            'business_id'     => 'nullable|string|max:191',
            'phone_number_id' => 'nullable|string|max:191',
            'waba_id'         => 'nullable|string|max:191',
            'display_number'  => 'nullable|string|max:64',
            'access_token'    => 'nullable|string|max:1024',
            'verify_token'    => 'nullable|string|max:191',
            'verified_at'     => 'nullable|string|max:64',
        ]);

        $res = $this->whatsapp->saveConfigForCaller($request->user(), $data);
        if (empty($res['ok'])) {
            return sendError($res['error'] ?? 'Could not save.', [], 422);
        }

        $sender = $this->whatsapp->senderForCaller($request->user());

        return sendSuccess('WhatsApp settings saved', [
            'config'     => $res['config'],
            'configured' => WhatsappService::isConfigured($res['config'], $sender['is_provider']),
        ]);
    }

    /**
     * One entry point for every Meta-facing action, mirroring the edge function's
     * `action` switch so the dashboard's call sites stay one-for-one.
     */
    public function action(Request $request)
    {
        $data = $request->validate([
            'action'     => 'required|string|in:status,list_templates,create_template,delete_template,send_template,send_text,send_campaign',
            'to'         => 'nullable|string|max:32',
            'text'       => 'nullable|string',
            'bodyText'   => 'nullable|string',
            'template'   => 'nullable|array',
            'variables'  => 'nullable|array',
            'recipients' => 'nullable|array',
            'campaignId' => 'nullable|integer',
            'customerId' => 'nullable|integer',
            'name'       => 'nullable|string|max:512',
            'language'   => 'nullable|string|max:16',
            'category'   => 'nullable|string|max:32',
            'headerText' => 'nullable|string|max:120',
            'headerImageUrl' => 'nullable|url|max:2048',
            'headerVideoUrl' => 'nullable|url|max:2048',
            'footerText' => 'nullable|string|max:120',
            'examples'   => 'nullable|array',
        ]);

        $sender = $this->whatsapp->senderForCaller($request->user());
        $action = $data['action'];

        // Status is answerable without a token — it is how the page explains that one
        // is missing in the first place.
        if ($action === 'status') {
            return sendSuccess('OK', $this->whatsapp->status($sender));
        }

        if (! $sender['token']) {
            return sendSuccess('OK', ['ok' => false, 'error' => $sender['is_provider']
                ? 'Add your WhatsApp access token on the WhatsApp page.'
                : 'Bookboll\'s WhatsApp access token is not configured.']);
        }

        $result = match ($action) {
            'list_templates'  => $this->whatsapp->listTemplates($sender),
            'create_template' => $this->whatsapp->createTemplate($sender, $data),
            'delete_template' => $this->whatsapp->deleteTemplate($sender, (string) ($data['name'] ?? '')),
            default           => null,
        };
        if ($result !== null) {
            return sendSuccess('OK', $result);
        }

        // The remaining actions all put a message on the wire, so they need a number.
        if (! $sender['phone_number_id']) {
            return sendSuccess('OK', ['ok' => false, 'error' => 'WhatsApp is not configured yet — set the phone number ID on the WhatsApp page.']);
        }

        return sendSuccess('OK', match ($action) {
            'send_template' => $this->whatsapp->sendTemplate($sender, $data),
            'send_text'     => $this->whatsapp->sendText($sender, $data),
            'send_campaign' => $this->whatsapp->sendCampaign($sender, $data),
        });
    }

    /** The recent-messages panel on the WhatsApp page. */
    public function outbound(Request $request)
    {
        $sender = $this->whatsapp->senderForCaller($request->user());

        return sendSuccess('Recent messages', [
            'outbound' => $this->whatsapp->recentOutbound($sender, (int) $request->query('limit', 15)),
        ]);
    }

    /* ── Queued campaigns ────────────────────────────────────────────────── */

    public function queueIndex(Request $request)
    {
        return $this->queue->index($request);
    }

    public function queueStore(Request $request)
    {
        return $this->queue->queue($request);
    }

    public function queueDismiss(Request $request, $id)
    {
        return $this->queue->dismiss($request, $id);
    }

    public function queueCancel(Request $request, $id)
    {
        return $this->queue->cancel($request, $id);
    }

    public function queueProcess(Request $request)
    {
        return $this->queue->process($request);
    }
}
