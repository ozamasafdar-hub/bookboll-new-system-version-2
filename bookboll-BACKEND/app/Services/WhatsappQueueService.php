<?php

namespace App\Services;

use App\Models\MarketingCampaign;
use App\Models\WhatsappQueuedCampaign;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Campaigns composed against a template Meta has not approved yet.
 *
 * WhatsApp forbids free-form bulk sends, so a marketing campaign needs an APPROVED
 * template. Rather than send the provider away to come back later, the whole campaign
 * is snapshotted and this pump fires it the moment Meta approves — or records the
 * rejection reason if Meta declines. The dashboard runs the pump whenever the
 * WhatsApp page or the marketing composer opens, exactly as before.
 *
 * The pump lived in the browser under Supabase. Moving it here is the one behavioural
 * upgrade: claiming a row and sending it is now a single guarded transition, so two
 * open tabs cannot both dispatch the same campaign.
 */
class WhatsappQueueService
{
    public function __construct(
        protected WhatsappService $whatsapp,
    ) {}

    /** Snapshot a campaign to send once its template is approved. */
    public function queue(Request $request)
    {
        $data = $request->validate([
            'template_name'     => 'required|string|max:191',
            'template_language' => 'nullable|string|max:16',
            'payload'           => 'required|array',
        ]);

        $row = WhatsappQueuedCampaign::create([
            'owner_user_id'     => AccessScope::ownerUserId($request->user()),
            'template_name'     => $data['template_name'],
            // `nullable` means the key may be absent entirely, not just empty.
            'template_language' => ($data['template_language'] ?? '') ?: 'en_US',
            'payload'           => $data['payload'],
            'status'            => WhatsappQueuedCampaign::STATUS_AWAITING,
        ]);

        return sendSuccess('Campaign queued', ['row' => $this->map($row)], 201);
    }

    /** The caller's queue — undismissed rows, newest first. */
    public function index(Request $request)
    {
        return sendSuccess('Queue', ['queue' => $this->queueFor($request->user())->map(fn ($r) => $this->map($r))->values()]);
    }

    public function dismiss(Request $request, $id)
    {
        $row = $this->ownedRow($request, $id);
        if (! $row) {
            return sendError('Queued campaign not found.', [], 404);
        }

        $row->update(['dismissed_at' => now()]);

        return sendSuccess('Dismissed', ['ok' => true]);
    }

    /** Cancel is only meaningful while the campaign has not gone out. */
    public function cancel(Request $request, $id)
    {
        $row = $this->ownedRow($request, $id);
        if (! $row) {
            return sendError('Queued campaign not found.', [], 404);
        }
        if ($row->status !== WhatsappQueuedCampaign::STATUS_AWAITING) {
            return sendError('That campaign is no longer waiting for approval.', [], 409);
        }

        $row->update(['status' => WhatsappQueuedCampaign::STATUS_CANCELLED]);

        return sendSuccess('Cancelled', ['row' => $this->map($row->fresh())]);
    }

    /**
     * The pump. Checks Meta for each waiting campaign's template and either fires it
     * (APPROVED) or records why it will never fire (REJECTED). Safe to call often: a
     * row is claimed with a conditional update before anything is dispatched, so a
     * second concurrent run finds nothing left to claim.
     */
    public function process(Request $request)
    {
        $user   = $request->user();
        $sender = $this->whatsapp->senderForCaller($user);

        $queue = $this->queueFor($user)->where('status', WhatsappQueuedCampaign::STATUS_AWAITING);
        if ($queue->isEmpty()) {
            return sendSuccess('Queue processed', ['checked' => 0, 'sent' => [], 'rejected' => []]);
        }

        $res = $this->whatsapp->listTemplates($sender);
        if (empty($res['ok'])) {
            return sendSuccess('Queue processed', [
                'checked' => 0, 'sent' => [], 'rejected' => [], 'error' => $res['error'] ?? 'Could not read templates.',
            ]);
        }
        $byName = collect($res['templates'] ?? [])->keyBy('name');

        $sent = [];
        $rejected = [];

        foreach ($queue as $row) {
            $tpl = $byName->get($row->template_name);
            if (! $tpl) {
                continue;   // Meta is still syncing this one
            }

            if (($tpl['status'] ?? '') === 'REJECTED') {
                $reason = $tpl['rejectedReason'] ?: 'Rejected by Meta review';
                $row->update(['status' => WhatsappQueuedCampaign::STATUS_REJECTED, 'reject_reason' => $reason]);
                $rejected[] = $this->map($row->fresh());

                continue;
            }

            if (($tpl['status'] ?? '') !== 'APPROVED') {
                continue;   // still PENDING
            }

            // Claim it before sending: whoever wins this update owns the dispatch.
            $claimed = DB::table('whatsapp_queued_campaigns')
                ->where('id', $row->id)
                ->where('status', WhatsappQueuedCampaign::STATUS_AWAITING)
                ->update([
                    'status'     => WhatsappQueuedCampaign::STATUS_SENT,
                    'sent_at'    => now(),
                    'updated_at' => now(),
                ]);
            if (! $claimed) {
                continue;
            }

            $payload = is_array($row->payload) ? $row->payload : [];

            // The ledger row is written at SEND time, like a live campaign — the
            // record has to reflect when the messages actually went out.
            $campaignId = $payload['campaignId'] ?? null;
            if (! $campaignId && ! empty($payload['campaignMeta'])) {
                $campaignId = $this->recordQueuedCampaign($request, $payload['campaignMeta']);
            }

            $out = $this->whatsapp->sendCampaign($sender, [
                'template'    => ['name' => $row->template_name, 'language' => $row->template_language, 'headerImageUrl' => $payload['headerImageUrl'] ?? '', 'headerVideoUrl'=>$payload['headerVideoUrl'] ?? ''],
                'bodyText'    => $payload['bodyText'] ?? '',
                'campaignId'  => $campaignId,
                'recipients'  => $payload['recipients'] ?? [],
            ]);

            $result = ! empty($out['ok'])
                ? ['sent' => $out['sent'], 'failed' => $out['failed'], 'dropped' => $out['dropped'] ?? 0]
                : ['sent' => 0, 'failed' => count($payload['recipients'] ?? []), 'error' => $out['error'] ?? 'Send failed.'];

            $row->update(['result' => $result]);
            $sent[] = $this->map($row->fresh());
        }

        return sendSuccess('Queue processed', [
            'checked'  => $queue->count(),
            'sent'     => $sent,
            'rejected' => $rejected,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, WhatsappQueuedCampaign> */
    protected function queueFor($user)
    {
        $ownerId = AccessScope::ownerUserId($user);

        return WhatsappQueuedCampaign::query()
            ->whereNull('dismissed_at')
            ->when($ownerId !== null,
                fn ($q) => $q->where('owner_user_id', $ownerId),
                fn ($q) => $q->whereNull('owner_user_id'))
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    protected function ownedRow(Request $request, $id): ?WhatsappQueuedCampaign
    {
        $ownerId = AccessScope::ownerUserId($request->user());

        return WhatsappQueuedCampaign::query()
            ->whereKey($id)
            ->when($ownerId !== null,
                fn ($q) => $q->where('owner_user_id', $ownerId),
                fn ($q) => $q->whereNull('owner_user_id'))
            ->first();
    }

    /** Write the compliance ledger row for a campaign that is going out right now. */
    protected function recordQueuedCampaign(Request $request, array $meta): ?int
    {
        try {
            return MarketingCampaign::create([
                'owner_user_id'   => AccessScope::ownerUserId($request->user()),
                'created_by'      => $request->user()?->id,
                'sender_label'    => $meta['senderLabel'] ?? $meta['sender_label'] ?? 'Bookboll',
                'channel'         => 'whatsapp',
                'audience'        => $meta['audience'] ?? null,
                'subject'         => $meta['subject'] ?? null,
                'body'            => $meta['body'] ?? null,
                'html'            => $meta['html'] ?? null,
                'recipient_count' => (int) ($meta['recipientCount'] ?? $meta['recipient_count'] ?? 0),
            ])->id;
        } catch (\Throwable $e) {
            return null;   // the ledger must never block a send
        }
    }

    /** @return array<string, mixed> */
    protected function map(WhatsappQueuedCampaign $r): array
    {
        return [
            'id'                => $r->id,
            'owner_user_id'     => $r->owner_user_id,
            'template_name'     => $r->template_name,
            'template_language' => $r->template_language,
            'payload'           => $r->payload,
            'status'            => $r->status,
            'reject_reason'     => $r->reject_reason,
            'result'            => $r->result,
            'sent_at'           => optional($r->sent_at)?->toIso8601String(),
            'dismissed_at'      => optional($r->dismissed_at)?->toIso8601String(),
            'created_at'        => optional($r->created_at)?->toIso8601String(),
        ];
    }
}
