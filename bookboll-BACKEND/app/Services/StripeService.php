<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\StripeSession;
use App\Support\PremiumPlan;
use App\Support\StripeDocumentLink;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
use Stripe\Charge;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService
{
    public function forPayment(Payment $payment): array
    {
        if ($payment->method !== PaymentMethod::ONLINE) {
            return ['available' => false, 'reason' => 'not_online_payment'];
        }

        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            return [
                'available' => false,
                'reason'    => 'stripe_not_configured',
                'error'     => 'Stripe secret key is not configured.',
            ];
        }

        Stripe::setApiKey($secret);
        $isTestMode = str_starts_with($secret, 'sk_test_');

        $result = [
            'available'       => true,
            'is_test_mode'    => $isTestMode,
            'error'           => null,
            'payment_intent'  => null,
            'charge'          => null,
            'checkout_session' => null,
            'card'            => null,
            'billing'         => null,
            'customer'        => null,
            'dashboard_links' => [],
        ];

        try {
            $paymentIntent = null;
            $charge          = null;

            if ($payment->payment_intent_id) {
                $paymentIntent = PaymentIntent::retrieve(
                    $payment->payment_intent_id,
                    ['expand' => ['latest_charge.payment_method_details', 'latest_charge.billing_details']]
                );
                $result['payment_intent'] = $this->formatPaymentIntent($paymentIntent);
                $result['dashboard_links']['payment_intent'] = $this->dashboardUrl(
                    'payments',
                    $paymentIntent->id,
                    $isTestMode
                );

                if ($paymentIntent->latest_charge && is_object($paymentIntent->latest_charge)) {
                    $charge = $paymentIntent->latest_charge;
                }
            }

            if ($payment->charge_id) {
                try {
                    $charge = Charge::retrieve(
                        $payment->charge_id,
                        ['expand' => ['payment_method_details', 'billing_details']]
                    );
                } catch (Exception $e) {
                    Log::warning('Stripe charge retrieve failed', [
                        'charge_id'  => $payment->charge_id,
                        'payment_id' => $payment->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            if ($charge) {
                $result['charge'] = $this->formatCharge($charge);
                $result['dashboard_links']['charge'] = $this->dashboardUrl(
                    'payments',
                    $charge->id,
                    $isTestMode
                );

                $card = $charge->payment_method_details->card ?? null;
                if ($card) {
                    $result['card'] = $this->formatCard($card);
                }

                $billing = $charge->billing_details ?? null;
                if ($billing) {
                    $result['billing'] = $this->formatBillingDetails($billing);
                }
            }

            $checkoutSession = $this->resolveCheckoutSession($payment, $paymentIntent);
            if ($checkoutSession) {
                $result['checkout_session'] = $this->formatCheckoutSession($checkoutSession);
                $result['dashboard_links']['checkout_session'] = $this->dashboardUrl(
                    'checkout/sessions',
                    $checkoutSession->id,
                    $isTestMode
                );
            }

            $user = $payment->relationLoaded('user') ? $payment->user : $payment->user()->first();
            if ($user?->stripe_id) {
                $result['customer'] = [
                    'stripe_customer_id' => $user->stripe_id,
                    'dashboard_link'     => $this->dashboardUrl('customers', $user->stripe_id, $isTestMode),
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to load Stripe payment details', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'reason'    => 'stripe_api_error',
                'error'     => $e->getMessage(),
            ];
        }

        if (! $result['payment_intent'] && ! $result['charge'] && ! $result['checkout_session']) {
            $result['available'] = false;
            $result['reason']    = 'no_stripe_ids';
            $result['error']     = 'No Stripe payment intent, charge, or checkout session found for this payment.';
        }

        return $result;
    }

    public function constructWebhookEvent(string $payload, ?string $signatureHeader): \Stripe\Event
    {
        $secret = $this->resolveWebhookSecret();
        if (! $secret) {
            throw new Exception('Stripe webhook secret is not configured.');
        }

        if (! $signatureHeader) {
            throw new Exception('Missing Stripe-Signature header.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $secret);
    }

    public function handleWebhookEvent(\Stripe\Event $event): void
    {
        if (app(MembershipListingService::class)->webhook($event)) return;
        $type = $event->type;

        if (in_array($type, ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'], true)) {
            app(PaymentRefundService::class)->webhook($event);
            return;
        }

        // Membership subscriptions are billed by Stripe; bookings are one-off payments.
        $membership = app(MembershipBillingService::class);
        // Providers also pay US: Premium plans and the one-time custom-domain fee.
        $providerPlan = app(ProviderPlanService::class);

        // A provider's OWN subscription is a different animal from a customer's
        // membership, so it is claimed by metadata before the membership handlers see
        // it — matching on the subscription id alone would be a silent no-op today and
        // a real mix-up the day the two id spaces overlap.
        if (str_starts_with($type, 'customer.subscription.')
            && ($event->data->object->metadata['source'] ?? null) === PremiumPlan::SOURCE_PLAN) {
            // The event's own generation time goes with it: Stripe does not guarantee
            // delivery order, so arrival order cannot decide which state wins.
            $at = (int) ($event->created ?? 0) ?: null;
            match ($type) {
                'customer.subscription.deleted' => $providerPlan->endSubscription($event->data->object, $at),
                default                         => $providerPlan->syncSubscription($event->data->object, $at),
            };

            return;
        }

        if (str_starts_with($type, 'invoice.')
            || str_starts_with($type, 'invoice_payment.')
            || str_starts_with($type, 'customer.subscription.')) {
            match ($type) {
                'invoice.payment_succeeded'    => $membership->invoicePaid($event->data->object),
                'invoice.payment_failed'       => $membership->invoiceFailed($event->data->object),
                // Basil's replacement for invoice.payment_succeeded. Both may arrive;
                // invoicePaid is keyed on the invoice id, so the second one is a no-op.
                'invoice_payment.paid'         => $membership->invoicePaymentPaid($event->data->object),
                'customer.subscription.updated' => $membership->subscriptionUpdated($event->data->object),
                'customer.subscription.deleted' => $membership->subscriptionDeleted($event->data->object),
                default                        => null,
            };

            return;
        }

        if ($type === 'checkout.session.completed') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;
            $source  = $session->metadata['source'] ?? null;

            if ($source === PremiumPlan::SOURCE_PLAN) {
                $providerPlan->fulfillPlanSession($session, (int) ($event->created ?? 0) ?: null);

                return;
            }

            if ($source === PremiumPlan::SOURCE_DOMAIN) {
                if (($session->payment_status ?? null) === 'paid') {
                    $providerPlan->fulfillDomainSession($session);
                }

                return;
            }

            if (($session->mode ?? '') === 'subscription') {
                $membership->checkoutCompleted($session);

                return;
            }

            if (($session->payment_status ?? null) === 'paid') {
                $this->fulfillPaidCheckoutSession($session->id, $session);
            }

            return;
        }

        if ($type === 'checkout.session.async_payment_succeeded') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;

            if (($session->metadata['source'] ?? null) === PremiumPlan::SOURCE_DOMAIN) {
                $providerPlan->fulfillDomainSession($session);

                return;
            }

            $this->fulfillPaidCheckoutSession($session->id, $session);

            return;
        }

        if ($type === 'checkout.session.expired') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;
            $source  = $session->metadata['source'] ?? null;

            if ($source === PremiumPlan::SOURCE_DOMAIN) {
                $providerPlan->abandonDomainSession($session);

                return;
            }

            // An expired Premium checkout granted nothing, so there is nothing to
            // undo — the business row is only written on fulfilment.
            if ($source === PremiumPlan::SOURCE_PLAN) {
                return;
            }

            if (($session->mode ?? '') === 'subscription') {
                $membership->checkoutExpired($session);

                return;
            }

            $this->abandonCheckoutSession($session->id, cancelPendingBookings: true, expireStripeSession: false);

            return;
        }
    }

    /**
     * Expire an open Stripe Checkout session and optionally cancel linked unpaid customer bookings.
     * Idempotent — safe to call from cancel URL, webhooks, and auto-cancel flows.
     *
     * @return array{status:string,source:string,booking_ids:array,session:?StripeCheckoutSession,message:?string}
     */
    public function abandonCheckoutSession(
        string $sessionId,
        bool $cancelPendingBookings = true,
        bool $expireStripeSession = true
    ): array {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            throw new Exception('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($secret);
        $stripeClient = new StripeClient($secret);

        $session = StripeCheckoutSession::retrieve($sessionId);
        $source  = $session->metadata['source'] ?? 'customer';
        $dbRow   = StripeSession::where('session_id', $sessionId)->first();

        if ($dbRow?->processed || ($session->payment_status ?? null) === 'paid') {
            return [
                'status'      => 'completed',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        $bookingIds = $this->resolveBookingIdsFromSession($session);

        try {
            DB::transaction(function () use (
                $sessionId,
                $session,
                $source,
                $bookingIds,
                $cancelPendingBookings,
                $expireStripeSession,
                $stripeClient
            ) {
                StripeSession::where('session_id', $sessionId)->lockForUpdate()->first();

                if ($expireStripeSession && ($session->status ?? null) === 'open') {
                    $stripeClient->checkout->sessions->expire($sessionId);
                    Log::info('Expired Stripe checkout session on abandonment', [
                        'session_id' => $sessionId,
                        'source'     => $source,
                    ]);
                }

                if ($cancelPendingBookings && $source === 'customer' && ! empty($bookingIds)) {
                    $this->cancelUnpaidBookingsForCheckout($bookingIds, 'Payment was not completed.');
                }

                // A hosted event the host never paid for must not linger: its
                // courts go back on sale and the unpublished event is removed.
                if ($cancelPendingBookings && $source === CustomerGameService::CHECKOUT_SOURCE) {
                    $uuid = $session->metadata['hosted_game_uuid'] ?? null;

                    if ($uuid) {
                        app(CustomerGameService::class)->discardUnpaidGame((string) $uuid);
                    }
                }
            });
        } catch (Exception $e) {
            Log::error('Failed to abandon Stripe checkout session', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'status'      => 'cancelled',
            'source'      => $source,
            'booking_ids' => $bookingIds,
            'session'     => $session,
            'message'     => 'Payment was not completed.',
        ];
    }

    /**
     * @return array{processed:bool,already_processed:bool,booking_ids:array,source:string,error:?string}
     */
    public function fulfillPaidCheckoutSession(string $sessionId, ?StripeCheckoutSession $session = null): array
    {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => 'customer',
                'error'             => 'Stripe secret key is not configured.',
            ];
        }

        Stripe::setApiKey($secret);

        $session = $session ?? StripeCheckoutSession::retrieve($sessionId);

        if (($session->payment_status ?? null) !== 'paid') {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'Payment not completed.',
            ];
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        if (! $paymentIntentId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'No payment intent on checkout session.',
            ];
        }

        // The charge comes expanded so its hosted receipt is in hand without a
        // second round trip; the id is read the same way whether or not it is.
        $intent   = PaymentIntent::retrieve(['id' => $paymentIntentId, 'expand' => ['latest_charge']]);
        $chargeId = is_string($intent->latest_charge)
            ? $intent->latest_charge
            : ($intent->latest_charge->id ?? null);

        if (! $chargeId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'No charge found for payment.',
            ];
        }

        $receiptUrl = $this->receiptUrlForCharge($intent->latest_charge, $chargeId);

        $userId = $session->metadata['user_id'] ?? null;
        if (! $userId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'Missing user in session metadata.',
            ];
        }

        $bookingIds     = $this->resolveBookingIdsFromSession($session);
        $paymentType    = $session->metadata['payment_type'] ?? 'full';
        $source         = $session->metadata['source'] ?? 'customer';
        $hostedGameUuid = $session->metadata['hosted_game_uuid'] ?? null;

        // A hosted event at the host's OWN SPOT books no court, so having no bookings
        // is correct for it — its fulfilment is keyed to the game, not to a booking.
        // Bailing out here would take the host's money and never publish the event.
        $hostedGameOnly = $source === CustomerGameService::CHECKOUT_SOURCE && $hostedGameUuid;

        if (empty($bookingIds) && ! $hostedGameOnly) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $source,
                'error'             => 'No bookings linked to this checkout session.',
            ];
        }

        try {
            $result = DB::transaction(function () use (
                $sessionId,
                $bookingIds,
                $paymentType,
                $source,
                $userId,
                $chargeId,
                $paymentIntentId,
                $hostedGameUuid
            ) {
                $settled   = [];
                $unsettled = [];

                $stripeSession = StripeSession::where('session_id', $sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $stripeSession) {
                    $stripeSession = StripeSession::create([
                        'session_id'  => $sessionId,
                        'booking_id'  => $bookingIds[0] ?? null,
                        'booking_ids' => $bookingIds,
                        'source'      => $source,
                        'processed'   => false,
                    ]);
                }

                if ($stripeSession->processed) {
                    return [
                        'processed'         => true,
                        'already_processed' => true,
                        'booking_ids'       => $bookingIds,
                        'settled'           => [],
                        'unsettled'         => [],
                        'source'            => $source,
                        'error'             => null,
                    ];
                }

                Log::info('Fulfilling Stripe checkout session', [
                    'session_id'   => $sessionId,
                    'booking_ids'  => $bookingIds,
                    'payment_type' => $paymentType,
                    'source'       => $source,
                ]);

                if ($source === CustomerGameService::CHECKOUT_SOURCE) {
                    // A hosted event pays for its courts and the seats service fee
                    // in one charge, and a bracket's courts can each be on a
                    // different payment method — more than the single payment_type
                    // this generic path carries. CustomerGameService settles every
                    // row it covers and publishes the event.
                    app(CustomerGameService::class)->settleHostCheckout(
                        (string) ($hostedGameUuid ?? ''),
                        $chargeId,
                        $paymentIntentId
                    );
                } else {
                    [$settled, $unsettled] = $this->settleBookingsForCharge(
                        $bookingIds,
                        (int) $userId,
                        $paymentType,
                        $chargeId,
                        $paymentIntentId
                    );
                }

                $stripeSession->update([
                    'processed'   => true,
                    'booking_id'  => $stripeSession->booking_id ?? ($bookingIds[0] ?? null),
                    'booking_ids' => $bookingIds,
                    'source'      => $source,
                ]);

                return [
                    'processed'         => true,
                    'already_processed' => false,
                    'booking_ids'       => $bookingIds,
                    'settled'           => $settled,
                    'unsettled'         => $unsettled,
                    'source'            => $source,
                    'error'             => null,
                ];
            });

            // Deposit, balance or full — every payment row this charge settled now
            // carries Stripe's receipt, so the dashboard can open it at once
            // instead of waiting for the hourly backfill. Outside the transaction
            // and non-fatal: a receipt is never worth a failed fulfilment.
            if ($result['processed']) {
                $this->stampReceiptUrl($chargeId, $receiptUrl);
            }

            if ($result['processed'] && ! $result['already_processed']) {
                // Only the bookings that actually took the money get told about it.
                $this->dispatchPaymentReceivedNotifications(
                    $result['settled'] ?: $result['booking_ids'],
                    $paymentType,
                    $sessionId
                );
            }

            if (! empty($result['unsettled'])) {
                // Real money arrived that no live booking could absorb. Nothing
                // automatic can put this right — a refund is a decision — so say
                // so once, loudly, with everything needed to action it.
                Log::error('Stripe payment could not be applied to every booking in its checkout', [
                    'session_id'        => $sessionId,
                    'payment_intent_id' => $paymentIntentId,
                    'charge_id'         => $chargeId,
                    'settled'           => $result['settled'],
                    'unsettled'         => $result['unsettled'],
                    'refund_required'   => empty($result['settled']),
                ]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Stripe checkout fulfillment failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => $bookingIds,
                'settled'           => [],
                'unsettled'         => [],
                'source'            => $source,
                'error'             => $e->getMessage(),
            ];
        }
    }

    /**
     * Stripe's hosted receipt for a charge, or null. Read off the expanded
     * charge when the intent carried one, otherwise fetched; a failure here is
     * logged and swallowed because the hourly backfill will try again.
     */
    protected function receiptUrlForCharge($charge, string $chargeId): ?string
    {
        try {
            if (! is_object($charge) || ! isset($charge->receipt_url)) {
                $charge = Charge::retrieve($chargeId);
            }

            return StripeDocumentLink::sanitize($charge->receipt_url ?? null);
        } catch (Exception $e) {
            Log::warning('Could not resolve Stripe receipt for charge', [
                'charge_id' => $chargeId,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Put the receipt on every payment the charge settled — the deposit row,
     * the balance row, a hosted event's court and seat rows — leaving any row
     * that already has a link alone. One query, no model events, never throws.
     */
    protected function stampReceiptUrl(string $chargeId, ?string $receiptUrl): int
    {
        if ($chargeId === '' || ! $receiptUrl) {
            return 0;
        }

        try {
            return Payment::query()
                ->where('charge_id', $chargeId)
                ->whereNull('stripe_receipt_url')
                ->update(['stripe_receipt_url' => $receiptUrl]);
        } catch (Exception $e) {
            Log::warning('Could not store Stripe receipt on payments', [
                'charge_id' => $chargeId,
                'error'     => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function dispatchPaymentReceivedNotifications(
        array $bookingIds,
        string $paymentType,
        string $sessionId
    ): void {
        if (empty($bookingIds)) {
            return;
        }

        try {
            $bookings = Booking::with(['user', 'venue', 'listing', 'payments'])
                ->whereIn('id', $bookingIds)
                ->get();

            if ($bookings->isEmpty()) {
                return;
            }

            $bookingService = app(BookingService::class);

            foreach ($bookings->groupBy('venue_id') as $venueId => $venueBookings) {
                if (! $venueId) {
                    continue;
                }

                $primaryBooking = $venueBookings->first();
                $paidPayment      = $primaryBooking->payments()
                    ->where('status', PaymentStatus::PAID)
                    ->where('method', PaymentMethod::ONLINE)
                    ->latest('id')
                    ->first();

                notifyVenueStaffRefresh((int) $venueId, [
                    'event'             => 'payment_received',
                    'payment_id'        => $paidPayment?->id,
                    'booking_id'        => $primaryBooking->id,
                    'booking_ids'       => $venueBookings->pluck('id')->values()->all(),
                    'venue_id'          => (int) $venueId,
                    'session_id'        => $sessionId,
                    'payment_type'      => $paymentType,
                    'all_paid'          => $venueBookings->every(
                        fn(Booking $b) => $b->payment_status === BookingPaymentStatus::FULLY_PAID
                    ),
                    'booking_confirmed' => $venueBookings->contains(
                        fn(Booking $b) => $b->status === BookingStatus::CONFIRMED
                    ),
                    'source'            => 'stripe',
                ]);
            }

            foreach ($bookings as $booking) {
                $bookingService->sendBookingNotification($booking, 'payment_received');
            }
        } catch (Exception $e) {
            Log::error('Stripe payment notifications failed', [
                'session_id'  => $sessionId,
                'booking_ids' => $bookingIds,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function getSessionReturnState(string $sessionId): array
    {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            throw new Exception('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($secret);

        $session = StripeCheckoutSession::retrieve($sessionId);
        $source  = $session->metadata['source'] ?? 'customer';
        $dbRow   = StripeSession::where('session_id', $sessionId)->first();

        if ($dbRow?->processed) {
            return [
                'status'      => 'completed',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        if (($session->payment_status ?? null) === 'paid') {
            $result = $this->fulfillPaidCheckoutSession($sessionId, $session);

            if ($result['processed']) {
                return [
                    'status'      => 'completed',
                    'source'      => $result['source'],
                    'booking_ids' => $result['booking_ids'],
                    'session'     => $session,
                    'message'     => null,
                ];
            }

            return [
                'status'      => 'processing',
                'source'      => $source,
                'booking_ids' => $result['booking_ids'],
                'session'     => $session,
                'message'     => $result['error'],
            ];
        }

        if (in_array($session->status ?? null, ['expired', 'open'], true) && ($session->payment_status ?? null) !== 'paid') {
            return [
                'status'      => 'cancelled',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        return [
            'status'      => 'cancelled',
            'source'      => $source,
            'booking_ids' => [],
            'session'     => $session,
            'message'     => 'Payment was not completed.',
        ];
    }

    public function checkoutReturnUrls(): array
    {
        return [
            'success_url' => url('/payment/success/{CHECKOUT_SESSION_ID}'),
            'cancel_url'  => url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}'),
        ];
    }

    protected function cancelUnpaidBookingsForCheckout(array $bookingIds, string $reason): void
    {
        foreach (array_unique(array_map('intval', $bookingIds)) as $bookingId) {
            if ($bookingId <= 0) {
                continue;
            }

            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking) {
                continue;
            }

            if ($booking->status === BookingStatus::CANCELLED) {
                continue;
            }

            if (
                ! in_array($booking->payment_method, [
                    BookingPaymentMethod::ONLINE_PAYMENT,
                    BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                ], true)
                || $booking->payment_status !== BookingPaymentStatus::PENDING
            ) {
                continue;
            }

            $previousStatus        = $booking->status;
            $previousPaymentStatus = $booking->payment_status;

            $booking->update([
                'cancelled_by'  => null,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
                'status'        => BookingStatus::CANCELLED,
            ]);

            processBookingCancellation(
                $booking,
                $booking->user_id,
                $reason,
                true,
                $previousStatus,
                $previousPaymentStatus
            );

            Log::info('Cancelled unpaid booking after checkout abandonment', [
                'booking_id'  => $booking->id,
                'booking_ref' => $booking->booking_id,
                'reason'      => $reason,
            ]);
        }
    }

    /**
     * Apply one Stripe charge across every booking its checkout covered.
     *
     * A single checkout routinely covers SEVERAL bookings — two consecutive
     * slots are two rows sharing one charge. This loop used to THROW the moment
     * any one of them was missing or cancelled, and because the caller runs it
     * inside a DB transaction, the throw unwound everything: a customer who
     * cancelled one slot while the other was still live had the money taken and
     * NOTHING marked paid. `stripe_sessions.processed` then stayed false, the
     * surviving booking still read as unpaid, and AutoCancelUnpaidDepositBookings
     * — which exists to protect exactly this customer — cancelled that one too.
     *
     * So settle what can be settled and report what could not. Paying into a
     * cancelled booking is still refused; only that booking is skipped, never
     * its siblings.
     *
     * @return array{0: list<int>, 1: list<array{booking_id:int, booking_ref?:string, reason:string}>}
     */
    protected function settleBookingsForCharge(
        array $bookingIds,
        int $userId,
        string $paymentType,
        string $chargeId,
        string $paymentIntentId
    ): array {
        $settled   = [];
        $unsettled = [];

        foreach ($bookingIds as $bookingId) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

            if (! $booking) {
                $unsettled[] = ['booking_id' => (int) $bookingId, 'reason' => 'not_found'];
                continue;
            }

            if ($booking->status === BookingStatus::CANCELLED) {
                $unsettled[] = [
                    'booking_id'  => (int) $bookingId,
                    'booking_ref' => $booking->booking_id,
                    'reason'      => 'cancelled',
                ];
                continue;
            }

            $this->applyPaymentToBooking(
                (int) $bookingId,
                $userId,
                $paymentType,
                $chargeId,
                $paymentIntentId
            );

            $settled[] = (int) $bookingId;
        }

        foreach ($settled as $bookingId) {
            $booking = Booking::find($bookingId);
            if ($booking) {
                autoConfirmBookingIfPaid($booking);
                regenerateBookingInvoice($booking);
            }
        }

        return [$settled, $unsettled];
    }

    protected function applyPaymentToBooking(
        int $bookingId,
        int $userId,
        string $paymentType,
        string $chargeId,
        string $paymentIntentId
    ): void {
        $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
        if (! $booking) {
            Log::warning('Booking not found during Stripe fulfillment', ['booking_id' => $bookingId]);

            return;
        }

        if ($booking->status === BookingStatus::CANCELLED) {
            throw new Exception(
                "Booking #{$booking->booking_id} is cancelled; refusing Stripe payment fulfillment."
            );
        }

        if ($paymentType === 'deposit') {
            $depositPayment = $booking->payments()
                ->where('status', PaymentStatus::PENDING)
                ->where('type', PaymentType::DEPOSIT)
                ->first();

            if ($depositPayment) {
                $depositPayment->update([
                    'notes'             => str_replace(
                        'pending',
                        'paid online via Stripe',
                        $depositPayment->notes ?? 'Deposit payment for booking #' . $booking->booking_id
                    ),
                    'charge_id'         => $chargeId,
                    'payment_intent_id' => $paymentIntentId,
                    'status'            => PaymentStatus::PAID,
                    'method'            => PaymentMethod::ONLINE,
                    'date'              => now()->format('Y-m-d'),
                ]);

                $booking->update([
                    'payment_status' => BookingPaymentStatus::DEPOSIT_PAID,
                    'payment_method' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                ]);

                // Led with our internal row id, which means nothing to the person
                // reading it, and omitted the one number they want: the amount.
                // The payment reference is the id staff can actually search by,
                // and the charge id is what reconciles against Stripe.
                $booking->history()->create([
                    'user_id'     => $userId,
                    'action'      => 'payment_received',
                    'description' => 'Deposit of ' . bookingMoney($depositPayment->amount, $booking)
                        . " received online via Stripe. Payment {$depositPayment->payment_id}, Stripe charge {$chargeId}.",
                ]);

                // The reward was taken off this deposit, so the points are spent
                // now — exactly as a full payment spends them below. Without this
                // a deposit booking kept the discount AND the points.
                $this->consumeLoyaltyQuote($booking);
            }

            return;
        }

        // Was anything already collected on this booking? If so, this is a balance
        // and Bookboll's fee was taken with the deposit — see creditProviderWallet.
        $depositAlreadyPaid = $booking->payments()
            ->where('status', PaymentStatus::PAID)
            ->whereIn('type', [PaymentType::DEPOSIT, PaymentType::FULL, PaymentType::REMAINING])
            ->exists();

        $pendingPayments = $booking->payments()
            ->where('status', PaymentStatus::PENDING)
            ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
            ->get();

        foreach ($pendingPayments as $payment) {
            $notes = $payment->notes ?? "Full payment for booking #{$booking->booking_id}";
            $notes = str_replace('pending', 'paid online via Stripe', $notes);

            $payment->update([
                'notes'             => $notes,
                'charge_id'         => $chargeId,
                'payment_intent_id' => $paymentIntentId,
                'status'            => PaymentStatus::PAID,
                'method'            => PaymentMethod::ONLINE,
                'date'              => now()->format('Y-m-d'),
            ]);

            $booking->history()->create([
                'user_id'     => $userId,
                'action'      => 'payment_received',
                'description' => 'Payment of ' . bookingMoney($payment->amount, $booking)
                    . " received online via Stripe. Payment {$payment->payment_id}, Stripe charge {$chargeId}.",
            ]);
        }

        $booking->update([
            'full_payment_received' => true,
            'payment_status'        => BookingPaymentStatus::FULLY_PAID,
            'payment_method'        => BookingPaymentMethod::ONLINE_PAYMENT,
        ]);

        // A balance after a deposit carries no reward (it was taken off the
        // deposit); a full payment's reward is Bookboll's, so the venue is paid
        // on the court price, not on what was left after the discount.
        $loyaltyRedeem = $depositAlreadyPaid ? 0.0 : max(0.0, (float) ($booking->loyalty_discount ?? 0));
        $this->creditProviderWallet($booking, (float) $pendingPayments->sum('amount'), $depositAlreadyPaid, $loyaltyRedeem, $pendingPayments->pluck('id')->all());

        // The charge is confirmed, so the points reserved at checkout are now
        // genuinely spent. Idempotent: a duplicate webhook finds the quote
        // already consumed and takes nothing twice.
        $this->consumeLoyaltyQuote($booking);
    }

    /**
     * A provider-owned venue's online money is theirs minus Bookboll's commission,
     * so record the split and credit their wallet.
     *
     * Commission is charged only when this collection is the WHOLE total. Under the
     * deposit model the deposit already WAS Bookboll's fee, so a balance collected
     * after a deposit is 100% the provider's.
     *
     * Best-effort: a booking is paid whether or not the ledger writes, and the
     * payment must never be rolled back over an accounting error.
     */
    /**
     * The commission rate to settle this booking at.
     *
     * Prefers the snapshot taken when the booking was made. Falls back to the
     * live marketplace rate only for bookings that predate the snapshot, which
     * is exactly how they were settled before.
     */
    /** Spend the rewards reservation a confirmed booking was made with. */
    protected function consumeLoyaltyQuote($booking): void
    {
        if (! $booking?->loyalty_quote_id) {
            return;
        }

        try {
            $quote = \App\Models\LoyaltyCheckoutQuote::where('quote_id', $booking->loyalty_quote_id)->first();
            if ($quote) {
                app(\App\Services\LoyaltyRedemptionService::class)->consume($quote, $booking->id);
            }
        } catch (\Throwable $e) {
            // The booking is paid either way; a ledger error must never undo a
            // confirmed payment.
            Log::error('Loyalty quote consume failed', [
                'booking_id' => $booking->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    protected function bookingCommissionPct($booking, $listing): float
    {
        if ($booking->commission_effective_pct !== null) {
            return (float) $booking->commission_effective_pct;
        }

        return (float) app(BookingWalletService::class)->courtCommissionPct($listing);
    }

    protected function creditProviderWallet($booking, float $collected, bool $depositAlreadyPaid, float $loyaltyRedeem = 0.0, array $receiptIds = []): void
    {
        try {
            $venue = $booking->venue;
            if (($booking->financial_context['operator'] ?? $venue?->operator_type) === 'bookboll') return;
            $ownerUserId = $venue?->owner_user_id;
            if (! $ownerUserId || $collected + $loyaltyRedeem <= 0) {
                return;   // Bookboll-run venue — no provider to pay
            }

            $wallets = app(BookingWalletService::class);
            $listing = $booking->listing;

            $wallets->recordBookingOnlinePayment([
                'bookingId'     => $booking->id,
                'ownerUserId'   => $ownerUserId,
                'countryId'     => $wallets->countryIdForVenue($venue),
                // The gross the customer was quoted: what Stripe collected plus the
                // reward Bookboll funded. The wallet takes its fee from that gross
                // and the reward out of the fee, so the venue's share is the same
                // whether points were used or not.
                'total'         => round($collected + $loyaltyRedeem, 2),
                'loyaltyRedeem' => $loyaltyRedeem,
                // The booking's stamped currency, not currency_override alone:
                // that skipped the venue's country currency and fell through to
                // a hard-coded 'QAR', crediting a Pakistani provider's wallet in
                // riyals. See Booking::currencyForVenue().
                'currency'      => $booking->currency ?: Booking::currencyForVenue($booking->venue_id),
                // The rate this booking was QUOTED at, not today's. Resolving it
                // live would let a later upgrade, cancellation or country-rate
                // change rewrite what a confirmed booking charged, and would
                // bill an own-site booking at the marketplace rate because the
                // channel is not knowable from here.
                'ratePct'       => ($depositAlreadyPaid || $booking->origin === 'manual_admin') ? 0 : $this->bookingCommissionPct($booking, $listing),
                'receiptIds'    => $receiptIds,
                'customerLabel' => $booking->user?->name ?: 'QR payment',
            ]);
        } catch (\Throwable $e) {
            Log::error('Provider wallet credit failed', [
                'booking_id' => $booking->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function resolveBookingIdsFromSession(StripeCheckoutSession $session): array
    {
        $metadata = $session->metadata ?? [];

        $allBookingIdsJson = $metadata['all_booking_ids'] ?? null;
        if ($allBookingIdsJson) {
            $ids = json_decode($allBookingIdsJson, true) ?? [];

            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        $mainBookingId   = $metadata['booking_id'] ?? null;
        $dueBookingsJson = $metadata['due_bookings'] ?? '[]';
        $dueIds          = json_decode($dueBookingsJson, true) ?? [];

        $bookingIds = [];
        if ($mainBookingId) {
            $bookingIds[] = (int) $mainBookingId;
        }
        if (! empty($dueIds)) {
            $bookingIds = array_merge($bookingIds, array_map('intval', $dueIds));
        }

        $dbRow = StripeSession::where('session_id', $session->id)->first();
        if ($dbRow && ! empty($dbRow->booking_ids)) {
            $bookingIds = array_merge($bookingIds, array_map('intval', (array) $dbRow->booking_ids));
        } elseif ($dbRow?->booking_id) {
            $bookingIds[] = (int) $dbRow->booking_id;
        }

        return array_values(array_unique(array_filter($bookingIds)));
    }

    protected function resolveCheckoutSession(Payment $payment, ?PaymentIntent $paymentIntent): ?StripeCheckoutSession
    {
        $dbSession = StripeSession::where('booking_id', $payment->booking_id)
            ->orWhereJsonContains('booking_ids', $payment->booking_id)
            ->latest('id')
            ->first();

        if ($dbSession?->session_id) {
            try {
                return StripeCheckoutSession::retrieve($dbSession->session_id);
            } catch (Exception $e) {
                Log::warning('Stripe checkout session retrieve from DB id failed', [
                    'session_id' => $dbSession->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($paymentIntent?->id) {
            try {
                $sessions = StripeCheckoutSession::all([
                    'payment_intent' => $paymentIntent->id,
                    'limit'          => 1,
                ]);
                if (! empty($sessions->data[0])) {
                    return $sessions->data[0];
                }
            } catch (Exception $e) {
                Log::warning('Stripe checkout session list by PI failed', [
                    'payment_intent_id' => $paymentIntent->id,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function formatPaymentIntent(PaymentIntent $pi): array
    {
        return [
            'id'                   => $pi->id,
            'status'               => $pi->status,
            'amount'               => $this->formatStripeAmount($pi->amount, $pi->currency),
            'amount_cents'         => $pi->amount,
            'amount_received'      => $this->formatStripeAmount($pi->amount_received ?? 0, $pi->currency),
            'currency'             => strtoupper($pi->currency ?? ''),
            'created_at'           => $this->formatTimestamp($pi->created),
            'description'          => $pi->description,
            'receipt_email'        => $pi->receipt_email,
            'capture_method'       => $pi->capture_method,
            'confirmation_method'  => $pi->confirmation_method,
            'payment_method_types' => $pi->payment_method_types ?? [],
            'latest_charge_id'     => is_string($pi->latest_charge) ? $pi->latest_charge : ($pi->latest_charge->id ?? null),
            'metadata'             => $this->metadataToArray($pi->metadata),
        ];
    }

    protected function formatCharge(Charge $charge): array
    {
        return [
            'id'               => $charge->id,
            'status'           => $charge->status,
            'paid'             => (bool) $charge->paid,
            'refunded'         => (bool) $charge->refunded,
            'amount'           => $this->formatStripeAmount($charge->amount, $charge->currency),
            'amount_cents'     => $charge->amount,
            'amount_refunded'  => $this->formatStripeAmount($charge->amount_refunded ?? 0, $charge->currency),
            'currency'         => strtoupper($charge->currency ?? ''),
            'created_at'       => $this->formatTimestamp($charge->created),
            'failure_code'     => $charge->failure_code,
            'failure_message'  => $charge->failure_message,
            'receipt_url'      => $charge->receipt_url,
            'payment_intent_id' => is_string($charge->payment_intent) ? $charge->payment_intent : ($charge->payment_intent->id ?? null),
            'metadata'         => $this->metadataToArray($charge->metadata),
        ];
    }

    protected function formatCheckoutSession(StripeCheckoutSession $session): array
    {
        return [
            'id'                 => $session->id,
            'status'             => $session->status,
            'payment_status'     => $session->payment_status,
            'mode'               => $session->mode,
            'amount_total'       => $this->formatStripeAmount($session->amount_total ?? 0, $session->currency),
            'amount_subtotal'    => $this->formatStripeAmount($session->amount_subtotal ?? 0, $session->currency),
            'currency'           => strtoupper($session->currency ?? ''),
            'customer_email'     => $session->customer_email,
            'created_at'         => $this->formatTimestamp($session->created),
            'expires_at'         => $this->formatTimestamp($session->expires_at),
            'payment_intent_id'  => is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null),
            'url'                => $session->url,
            'metadata'           => $this->metadataToArray($session->metadata),
        ];
    }

    protected function formatCard(object $card): array
    {
        return [
            'brand'       => $card->brand ?? null,
            'last4'       => $card->last4 ?? null,
            'exp_month'   => $card->exp_month ?? null,
            'exp_year'    => $card->exp_year ?? null,
            'funding'     => $card->funding ?? null,
            'country'     => $card->country ?? null,
            'wallet_type' => $card->wallet?->type ?? null,
        ];
    }

    protected function formatBillingDetails(object $billing): array
    {
        return [
            'name'    => $billing->name ?? null,
            'email'   => $billing->email ?? null,
            'phone'   => $billing->phone ?? null,
            'address' => $billing->address ? [
                'line1'       => $billing->address->line1 ?? null,
                'line2'       => $billing->address->line2 ?? null,
                'city'        => $billing->address->city ?? null,
                'state'       => $billing->address->state ?? null,
                'postal_code' => $billing->address->postal_code ?? null,
                'country'     => $billing->address->country ?? null,
            ] : null,
        ];
    }

    protected function formatStripeAmount(?int $amountCents, ?string $currency): string
    {
        $amountCents = (int) ($amountCents ?? 0);
        $value       = number_format($amountCents / 100, 2, '.', '');
        $code        = strtoupper($currency ?? 'QAR');

        return "{$code} {$value}";
    }

    protected function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? date('c', $timestamp) : null;
    }

    protected function metadataToArray($metadata): array
    {
        if (! $metadata) {
            return [];
        }

        return is_array($metadata) ? $metadata : (array) $metadata;
    }

    protected function dashboardUrl(string $path, string $id, bool $isTestMode): string
    {
        $base = $isTestMode ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

        return "{$base}/{$path}/{$id}";
    }

    protected function resolveStripeSecret(): ?string
    {
        $secret = trim((string) config('services.stripe.secret'));

        return $secret !== '' ? $secret : null;
    }

    protected function resolveWebhookSecret(): ?string
    {
        $secret = trim((string) config('services.stripe.webhook_secret'));

        return $secret !== '' ? $secret : null;
    }
}
