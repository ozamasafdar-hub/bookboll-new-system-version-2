<?php

namespace App\Listeners;

use App\Events\PaymentStatusChanged;
use App\Support\BookingPaymentSync;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a booking's status and invoice in line with its payments.
 *
 * A sync failure must never break the payment that triggered it — the money
 * has landed either way — so this logs and returns.
 */
final class SyncBookingAfterPaymentChange
{
    public function __construct(private BookingPaymentSync $sync)
    {}

    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;

        if (! BookingPaymentSync::concerns($payment)) {
            return;
        }

        try {
            $booking = $payment->booking()->first();
            if ($booking) {
                $this->sync->run($booking);
            }
        } catch (\Throwable $e) {
            Log::error('Booking payment sync failed: ' . $e->getMessage(), [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'exception'  => $e,
            ]);
        }
    }
}
