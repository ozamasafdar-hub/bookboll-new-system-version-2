<?php
namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\StripeSession;
use App\Services\BookingService;
use App\Services\StripeService;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class AutoCancelUnpaidDepositBookings extends Command
{
    protected $signature = 'bookings:auto-cancel-unpaid-deposit';
    protected $description = 'Auto-cancel unpaid online/deposit bookings after configured timeout';

    public function handle(): int
    {
        app(\App\Support\PaymentReleaseGate::class)->assertReady();
        $timeoutMinutes = (int) (Setting::where('key', 'deposit_payment_timeout_minutes')->value('value') ?: 10);

        if ($timeoutMinutes <= 0) {
            Log::info('Auto-cancel unpaid deposit bookings skipped because timeout is <= 0.');
            return self::SUCCESS;
        }

        // The customer sees `deposit_payment_timeout_minutes` as their countdown,
        // but bank approvals and 3-D Secure regularly land just after it. We
        // enforce a few minutes later than we advertise so a payment that is
        // seconds away is not thrown out. The message still quotes the timeout
        // the customer was actually shown.
        $graceMinutes    = max(0, (int) Setting::where('key', 'payment_timeout_grace_minutes')->value('value'));
        $effectiveMinutes = $timeoutMinutes + $graceMinutes;

        $reason = "The required payment was not completed within {$timeoutMinutes} minutes, so the booking has been cancelled.";
        $cutoff = now()->subMinutes($effectiveMinutes);
        $cancelledCount = 0;
        $stripeSecret = $this->resolveStripeSecret();
        $stripeClient = $stripeSecret ? new StripeClient($stripeSecret) : null;

        Booking::whereIn('payment_method', [
                BookingPaymentMethod::ONLINE_PAYMENT,
                BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            ])
            ->where('payment_status', BookingPaymentStatus::PENDING)
            ->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED])
            ->where('created_at', '<=', $cutoff)
            ->with(['user', 'venue:id,name,maps_url', 'listing:id,name'])
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($reason, &$cancelledCount, $stripeClient) {
                foreach ($bookings as $booking) {
                    try {
                        // Ask Stripe before we touch anything. Our own payment_status
                        // only moves when the checkout.session.completed webhook is
                        // delivered and processed, so a customer who approved the
                        // charge in their banking app moments ago still reads as
                        // unpaid here. Cancelling them is the one outcome we cannot
                        // undo automatically, so Stripe — not our stale column —
                        // decides whether the money arrived.
                        if ($stripeClient && $this->settlePaidCheckoutSessions($booking, $stripeClient)) {
                            continue;
                        }

                        // Nothing paid: close the checkout window first so the customer
                        // cannot pay into a booking we are about to cancel. Done outside
                        // the transaction so we never hold a row lock across Stripe HTTP
                        // round-trips.
                        if ($stripeClient) {
                            try {
                                $this->expireOpenStripeCheckoutSessions($booking, $stripeClient);
                            } catch (\Throwable $e) {
                                Log::error('Failed to expire Stripe checkout sessions for auto-cancelled booking', [
                                    'booking_id' => $booking->id,
                                    'error'      => $e->getMessage(),
                                ]);
                            }
                        }

                        DB::transaction(function () use ($booking, $reason, &$cancelledCount) {
                            $lockedBooking = Booking::whereKey($booking->id)
                                ->lockForUpdate()
                                ->first();

                            if (! $lockedBooking) {
                                return;
                            }

                            // Final re-check under the lock: fulfilment may have landed
                            // while we were talking to Stripe.
                            if (
                                ! in_array($lockedBooking->payment_method, [
                                    BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                                    BookingPaymentMethod::ONLINE_PAYMENT,
                                ], true)
                                || $lockedBooking->payment_status !== BookingPaymentStatus::PENDING
                                || ! in_array($lockedBooking->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)
                            ) {
                                return;
                            }

                            $previousStatus        = $lockedBooking->status;
                            $previousPaymentStatus = $lockedBooking->payment_status;

                            $lockedBooking->update([
                                'cancelled_by'  => null,
                                'cancelled_at'  => now(),
                                'cancel_reason' => $reason,
                                'status'        => BookingStatus::CANCELLED,
                            ]);

                            processBookingCancellation(
                                $lockedBooking,
                                $lockedBooking->user_id,
                                $reason,
                                true,
                                $previousStatus,
                                $previousPaymentStatus
                            );

                            $lockedBooking->loadMissing(['user', 'venue:id,name,maps_url', 'listing:id,name']);
                            app(BookingService::class)->sendBookingNotification($lockedBooking, 'cancelled');

                            $cancelledCount++;

                            Log::info('Auto-cancelled unpaid booking', [
                                'booking_id' => $lockedBooking->booking_id,
                                'user_id'    => $lockedBooking->user_id,
                                'venue_id'   => $lockedBooking->venue_id,
                                'listing_id' => $lockedBooking->listing_id,
                                'method'     => $lockedBooking->payment_method,
                            ]);
                        });
                    } catch (\Throwable $e) {
                        Log::error('Failed to auto-cancel unpaid booking', [
                            'booking_id' => $booking->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info("Auto-cancel unpaid bookings completed. Cancelled: {$cancelledCount}");

        return self::SUCCESS;
    }

    protected function resolveStripeSecret(): ?string
    {
        $secret = trim((string) config('services.stripe.secret'));
        if ($secret !== '') {
            return $secret;
        }

        Log::warning('Stripe secret key is missing. Stripe checkout sessions will not be expired by auto-cancel command.');
        return null;
    }

    /**
     * Check with Stripe whether any checkout session linked to this booking has
     * actually been paid, and settle it if so.
     *
     * Returns true when the booking must NOT be cancelled — either because the
     * money is in, or because we could not reach Stripe to find out. Skipping a
     * cancellation costs us one more pass a minute later; cancelling a booking
     * the customer has already paid for costs a refund and a lost slot, so an
     * unreadable session is always treated as "leave it alone".
     */
    protected function settlePaidCheckoutSessions(Booking $booking, StripeClient $stripeClient): bool
    {
        $sessions = StripeSession::where('processed', false)
            ->where(function ($query) use ($booking) {
                $query->where('booking_id', $booking->id)
                    ->orWhereJsonContains('booking_ids', $booking->id);
            })
            ->get();

        if ($sessions->isEmpty()) {
            return false;
        }

        $paidSessionIds = [];
        $unresolved     = false;

        foreach ($sessions as $stripeSession) {
            try {
                $session = $stripeClient->checkout->sessions->retrieve($stripeSession->session_id);

                if (($session->payment_status ?? null) === 'paid') {
                    $paidSessionIds[] = $stripeSession->session_id;
                }
            } catch (\Throwable $e) {
                $unresolved = true;

                Log::error('Could not read Stripe checkout session before auto-cancel; leaving booking alone', [
                    'booking_id' => $booking->id,
                    'session_id' => $stripeSession->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        foreach ($paidSessionIds as $sessionId) {
            Log::warning('Auto-cancel skipped: Stripe reports this booking as paid', [
                'booking_id'  => $booking->id,
                'booking_ref' => $booking->booking_id,
                'session_id'  => $sessionId,
            ]);

            try {
                // Settle it here rather than waiting on the webhook that has
                // clearly not arrived yet.
                app(StripeService::class)->fulfillPaidCheckoutSession($sessionId);
            } catch (\Throwable $e) {
                Log::error('Failed to fulfil paid Stripe session found during auto-cancel', [
                    'booking_id' => $booking->id,
                    'session_id' => $sessionId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return ! empty($paidSessionIds) || $unresolved;
    }

    protected function expireOpenStripeCheckoutSessions(Booking $booking, StripeClient $stripeClient): void
    {
        $sessions = StripeSession::where('processed', false)
            ->where(function ($query) use ($booking) {
                $query->where('booking_id', $booking->id)
                    ->orWhereJsonContains('booking_ids', $booking->id);
            })
            ->get();

        foreach ($sessions as $stripeSession) {
            try {
                $session = $stripeClient->checkout->sessions->retrieve($stripeSession->session_id);

                if (($session->status ?? null) === 'open') {
                    $stripeClient->checkout->sessions->expire($stripeSession->session_id);
                    Log::info('Expired Stripe checkout session for auto-cancelled booking', [
                        'booking_id'  => $booking->id,
                        'session_id'  => $stripeSession->session_id,
                        'booking_ref' => $booking->booking_id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to expire Stripe checkout session', [
                    'booking_id' => $booking->id,
                    'session_id' => $stripeSession->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
