<?php

namespace App\Observers;

use App\Models\Payment;
use App\Support\AdminAlerts;
use PaymentStatus;

/**
 * A payment landing raises a bell notification for the venue owner.
 *
 * Settings offered a "Payment received" switch for an alert nothing raised.
 * This is the alert it governs.
 *
 * Only a payment that is actually PAID, and only once: a row created paid, or
 * one that becomes paid later. A pending row turning paid must not be announced
 * twice, and a paid row being edited afterwards is not a new payment.
 */
class PaymentObserver
{
    public function creating(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::PAID && !$payment->paid_at) $payment->paid_at = $payment->date ?: now();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('payments', 'financial_context')) return;
        $booking = $payment->booking_id ? \App\Models\Booking::find($payment->booking_id) : null;
        $context = $booking?->financial_context;
        if (!$booking && $payment->subscription_id) {
            $subscription = \App\Models\ClassSubscription::find($payment->subscription_id);
            if ($subscription) $context = \App\Support\PaymentFinancialContext::forSubscription($subscription);
        }
        if ($context) {
            // Receipt links are written by the settlement service, never supplied by a checkout client.
            $payment->financial_context = array_merge($context, $payment->financial_context ?? []);
            if (!$payment->recipient) {
                $rows = \App\Support\PaymentReportRules::rows([array_merge($payment->getAttributes(), [
                    'id' => 0, 'financial_context' => $payment->financial_context,
                ])], array_merge($booking?->getAttributes() ?? [], ['financial_context' => $context]));
                $kind = ($context['operator'] ?? '') === 'bookboll' ? 'bookboll' : $rows[0]['recipient_kind'];
                if ($kind === 'bookboll') $payment->recipient = '@bookboll';
                if ($kind === 'provider') $payment->recipient = $context['provider_recipient'] ?? '@provider';
            }
        }
    }

    public function updating(Payment $payment): void
    {
        if ($payment->isDirty('status') && $payment->status === PaymentStatus::PAID && !$payment->paid_at) {
            $payment->paid_at = now();
        }
    }

    public function created(Payment $payment): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('payment_allocations')) {
            $allocations = \App\Support\OwnedVenueAllocation::plan($payment->getAttributes(), $payment->financial_context ?? []);
            if ($allocations) $payment->ownedAllocations()->createMany($allocations);
        }
        if ($payment->status === PaymentStatus::PAID) {
            $this->announce($payment);
        }
    }

    public function updated(Payment $payment): void
    {
        // Historical reconciliation records an assertion, not a new collection.
        if ($payment->wasChanged('reconciliation_batch_id')) return;
        if ($payment->wasChanged('status')
            && $payment->status === PaymentStatus::PAID
            && $payment->getOriginal('status') !== PaymentStatus::PAID) {
            $this->announce($payment);
        }
    }

    protected function announce(Payment $payment): void
    {
        // Resolved through the booking's own venue, so a payment can only ever
        // notify the provider it actually belongs to.
        $payment->loadMissing(['booking:id,venue_id,date,start_time', 'booking.venue:id,name']);
        $venueId = $payment->booking?->venue_id;

        if (! $venueId) {
            // Subscription and ad-hoc payments have no venue to attribute this
            // to. Memberships already announce themselves on creation.
            return;
        }

        $amount = trim(($payment->currency ? $payment->currency.' ' : '').rtrim(rtrim(number_format((float) $payment->amount, 2, '.', ''), '0'), '.'));

        AdminAlerts::toVenueOwner(
            (int) $venueId,
            'payment',
            'ok',
            'Payment received'.($amount !== '' ? ' — '.$amount : ''),
            implode(' · ', array_filter([
                $payment->booking?->venue?->name,
                $payment->method ? ucfirst((string) $payment->method) : null,
                optional($payment->booking)->date,
            ])),
            '/payments',
            'payment_received'
        );
    }
}
