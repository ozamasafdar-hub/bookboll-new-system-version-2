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
        $related = $this->report->authorizedQuery($actor)->where(function ($q) use ($selected, $anchor) {
            $q->where('id', $selected->id);
            if ($selected->booking_id) $q->orWhere('booking_id', $selected->booking_id);
            if ($selected->subscription_id) $q->orWhere('subscription_id', $selected->subscription_id);
            if ($anchor->charge_id) $q->orWhere('charge_id', $anchor->charge_id);
            if ($anchor->payment_intent_id) $q->orWhere('payment_intent_id', $anchor->payment_intent_id);
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
        $rows = $this->report->withDocuments($actor, $rows);
        $current = collect($rows)->first(fn ($r) => (string)$r['id'] === (string)($rowId ?? $id));
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
        return ['payment'=>$current, 'connected_transactions'=>array_values(array_filter($rows, fn ($r) => (string)$r['id'] !== (string)$current['id'])),
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
