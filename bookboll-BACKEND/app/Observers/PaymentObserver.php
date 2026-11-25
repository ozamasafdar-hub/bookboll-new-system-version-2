<?php

namespace App\Observers;

use App\Events\PaymentStatusChanged;
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
    public function created(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::PAID) {
            $this->announce($payment);
            // Born paid: the booking has to catch up exactly as if it had
            // just been marked paid.
            PaymentStatusChanged::dispatch($payment, null, (string) $payment->status);
        }
    }

    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        if ($payment->status === PaymentStatus::PAID
            && $payment->getOriginal('status') !== PaymentStatus::PAID) {
            $this->announce($payment);
        }

        // Every status change, not only to paid — a cancelled or refunded row
        // changes what the booking is owed just as much.
        PaymentStatusChanged::dispatch($payment, $payment->getOriginal('status'), (string) $payment->status);
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
