<?php
namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Support\AccessScope;
use App\Support\PaymentRefundAccess;
use App\Support\PaymentReportRules;
use App\Support\StripeDocumentLink;

final class PaymentDetailService
{
    public function __construct(private PaymentReportService $report) {}

    public function find($actor, int $id): Payment
    {
        return $this->report->authorizedQuery($actor)->with(['booking.venue','booking.listing','booking.user',
            'subscription.program.venue','user','ownedAllocations','paymentRefund'])->findOrFail($id);
    }

    /** Includes siblings across pagination/filters, but never outside the actor's ledger. */
    public function details($actor, int $id, ?string $rowId = null): array
    {
        $selected = $this->find($actor, $id);
        $anchor = $selected;
        if (!$selected->charge_id && !$selected->payment_intent_id && ($selected->booking_id || $selected->subscription_id)) {
            $siblings = $selected->booking_id ? Payment::where('booking_id', $selected->booking_id)->with('ownedAllocations')->get()
                : Payment::where('subscription_id', $selected->subscription_id)->get();
            $booking = $selected->booking;
            $facts = $booking ? array_merge($booking->getAttributes(), ['financial_context'=>$booking->financial_context ?? [], 'creation_context'=>$booking->creation_context ?? []]) : [];
            $classified = collect(PaymentReportRules::rows($siblings->map(fn ($p) => $this->report->raw($p))->all(), $facts))
                ->first(fn ($r) => (int)($r['source_payment_id'] ?? $r['id']) === $id);
            $links = $classified['source_receipt_ids'] ?? [];
            if (count($links) === 1) $anchor = $this->report->authorizedQuery($actor)->with('paymentRefund')->find((int)$links[0]) ?? $selected;
        }
        $connectionAnchor = $anchor;
        if (!$anchor->charge_id && !$anchor->payment_intent_id && $selected->booking_id) {
            $deposits = $this->report->authorizedQuery($actor)->where('booking_id',$selected->booking_id)
                ->where('type','deposit')->where('method','online')->get()
                ->filter(fn ($p) => $p->charge_id || $p->payment_intent_id);
            if ($deposits->map(fn ($p) => $p->charge_id ?: $p->payment_intent_id)->unique()->count() === 1)
                $connectionAnchor = $deposits->first();
        }
        $related = $this->report->authorizedQuery($actor)->where(function ($q) use ($selected, $connectionAnchor) {
            $q->where('id', $selected->id);
            if ($selected->booking_id) $q->orWhere('booking_id', $selected->booking_id);
            if ($selected->subscription_id) $q->orWhere('subscription_id', $selected->subscription_id);
            if ($connectionAnchor->charge_id) $q->orWhere('charge_id', $connectionAnchor->charge_id);
            if ($connectionAnchor->payment_intent_id) $q->orWhere('payment_intent_id', $connectionAnchor->payment_intent_id);
            if ($selected->booking?->checkout_reference)
                $q->orWhereHas('booking', fn ($b) => $b->where('checkout_reference',$selected->booking->checkout_reference));
        })->with(['booking.venue','booking.listing','booking.user','subscription.program.venue','user','ownedAllocations','paymentRefund'])->get();
        // A shared charge can have fee/share children without Stripe IDs. Include
        // those bookings' authorized siblings as well as their gross receipts.
        if ($related->pluck('booking_id')->filter()->unique()->count() > 1
            || $related->pluck('subscription_id')->filter()->unique()->count() > 1) {
            $related = $this->report->authorizedQuery($actor)->where(fn ($q) => $q->whereIn('id', $related->modelKeys())
                ->orWhereIn('booking_id', $related->pluck('booking_id')->filter()->unique())
                ->orWhereIn('subscription_id', $related->pluck('subscription_id')->filter()->unique()))
                ->with(['booking.venue','booking.listing','booking.user','subscription.program.venue','user','ownedAllocations','paymentRefund'])->get();
        }
        $rows = [];
        $venueIds = AccessScope::allowedVenueIds($actor);
        foreach ($related->groupBy(fn ($p) => $p->booking_id ? 'b'.$p->booking_id : ($p->subscription_id ? 's'.$p->subscription_id : 'p'.$p->id)) as $group) {
            $booking = $group->first()->booking;
            // Classification may need excluded siblings, but they are not returned.
            $siblings = $booking ? Payment::where('booking_id', $booking->id)->with('ownedAllocations')->get()
                : ($group->first()->subscription_id ? Payment::where('subscription_id', $group->first()->subscription_id)->get() : $group);
            $facts = $booking ? array_merge($booking->getAttributes(), ['financial_context'=>$booking->financial_context ?? [], 'creation_context'=>$booking->creation_context ?? []]) : [];
            foreach (PaymentReportRules::rows($siblings->map(fn ($p) => $this->report->raw($p))->all(), $facts) as $row) {
                $payment = $group->firstWhere('id', (int)($row['source_payment_id'] ?? $row['id']));
                if (!$payment) continue;
                $row = $this->report->describe($row, $payment, true);
                $row['can_view_booking'] = $venueIds === null || in_array((int)$row['venue_id'], $venueIds, true);
                $row['can_collect'] = $row['can_collect'] && $row['can_view_booking'];
                $row['relationship'] = $payment->id === $selected->id ? 'Same payment'
                    : ((($anchor->charge_id && $payment->charge_id === $anchor->charge_id)
                        || ($anchor->payment_intent_id && $payment->payment_intent_id === $anchor->payment_intent_id)) ? 'Same Stripe payment'
                        : ($payment->booking_id ? ($payment->booking_id === $selected->booking_id ? 'Same booking' : 'Connected booking') : 'Same membership'));
                $rows[] = $row;
            }
        }
        $rows = $this->report->withProcessorIdentity($actor, $this->report->withDocuments($actor, $rows));
        $current = collect($rows)->first(fn ($r) => $rowId !== null ? (string)$r['id'] === $rowId : (int)($r['source_payment_id'] ?? $r['id']) === $id);
        abort_unless($current && (int)($current['source_payment_id'] ?? $current['id']) === $id, 404);
        $source = $anchor;
        $refund = $source->paymentRefund;
        foreach ($rows as &$row) {
            if ((int)($row['source_payment_id'] ?? $row['id']) === $source->id
                || in_array($source->id, $row['source_receipt_ids'] ?? [])) {
                $row['stripe_receipt_url'] ??= StripeDocumentLink::sanitize($source->stripe_receipt_url);
            }
        }
        unset($row);
        $current['stripe_receipt_url'] ??= StripeDocumentLink::sanitize($source->stripe_receipt_url);
        $allocations = array_values(array_filter($rows, fn ($r) => $r['row_kind'] !== 'receipt'));
        $grouper = new \App\Support\PaymentReportGroups($allocations);
        $groups = $grouper->entries($allocations, AccessScope::isPlatform($actor));
        $shared = collect($groups)->first(fn ($g) => isset($g['bookings'])
            && collect($g['bookings'])->flatMap(fn ($b) => $b['allocations'])->contains(fn ($r) => (string)$r['id'] === (string)$current['id']));
        $current = $grouper->decorate($current);
        return ['payment'=>$current, 'checkout_payment'=>$shared,
            'bulk_history'=>\App\Support\PaymentBulkRules::allows($actor) ? \Illuminate\Support\Facades\DB::table('payment_bulk_action_items as item')
                ->join('payment_bulk_actions as audit','audit.id','=','item.bulk_action_id')->where('item.payment_id',$id)
                ->orderByDesc('audit.id')->get(['audit.id','audit.actor_name','audit.action','audit.reason','audit.created_at','item.row_id','item.before','item.after'])
                ->map(fn ($entry) => array_merge((array)$entry,['before'=>json_decode($entry->before,true),'after'=>json_decode($entry->after,true)]))->all() : [],
            'review'=>app(PaymentReviewService::class)->information($actor,$id),
            'checkout_payments'=>array_values(array_filter($groups, fn ($g) => isset($g['bookings']) && $g['id'] !== ($shared['id'] ?? null)
                && $g['checkout_reference'] === ($shared['checkout_reference'] ?? null))),
            'online_group'=>$shared && $shared['kind'] === 'online' ? array_intersect_key($shared, array_flip(['amount','currency','booking_count','scoped'])) : null,
            'connected_transactions'=>array_values(array_filter($rows, fn ($r) => (string)$r['id'] !== (string)$current['id'])),
            'refund_source_payment_id'=>$source->id,
            'can_request_refund'=>PaymentRefundAccess::allows($actor) && $source->method === 'online'
                && $source->status === 'paid' && !$refund && ($source->charge_id || $source->payment_intent_id),
            'can_refresh_refund'=>PaymentRefundAccess::allows($actor) && (bool)$refund,
            'refund_disabled_reason'=>config('preview.enabled') ? 'Stripe refunds are disabled in this Railway workspace. No money will be moved.' : null,
            'refund'=> $refund ? $this->refundSummary($refund) : null];
    }

    public function refundSummary(PaymentRefund $r): array
    {
        return ['id'=>$r->stripe_refund_id, 'status'=>$r->status, 'amount'=>\App\Support\Money::toMajor($r->amount_minor, $r->currency),
            'currency'=>$r->currency, 'requested_at'=>$r->created_at?->toIso8601String(), 'updated_at'=>$r->synced_at?->toIso8601String(),
            'receipt_url'=>StripeDocumentLink::sanitize($r->receipt_url), 'reason'=>$r->reason];
    }
}
