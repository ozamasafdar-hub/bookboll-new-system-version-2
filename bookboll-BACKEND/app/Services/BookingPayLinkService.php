<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\StripeSession;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

/**
 * The desk's "Pay by QR": a customer scans the code on the provider's screen,
 * lands on /pay/{uuid} with no session, sees what they owe and pays it.
 *
 * The booking uuid in the link is the only credential, so this deliberately
 * returns the minimum a payer needs to recognise their own booking — never the
 * customer's email, phone or the venue's internal ids.
 *
 * Payment itself goes through Stripe Checkout and is settled by the existing
 * webhook (StripeService::fulfillPaidCheckoutSession), so a QR payment lands in
 * exactly the same place as any other online booking payment.
 */
class BookingPayLinkService
{
    public function __construct(protected StripeService $stripeService)
    {}

    /** The bill behind a pay link. */
    public function bill(string $uuid)
    {
        $booking = $this->findBooking($uuid);
        if (! $booking) {
            return sendError('Booking not found — this payment link may be wrong or expired.', [], 404);
        }

        return sendSuccess('Booking bill', ['bill' => $this->mapBill($booking)]);
    }

    /**
     * Start a Stripe Checkout for whatever is still outstanding.
     * Returns the URL for the browser to follow.
     */
    public function createCheckout(string $uuid)
    {
        $booking = $this->findBooking($uuid);
        if (! $booking) {
            return sendError('Booking not found — this payment link may be wrong or expired.', [], 404);
        }

        if ($booking->status === \BookingStatus::CANCELLED) {
            return sendError('This booking was cancelled, so there is nothing to pay.');
        }

        $pending = $this->pendingPayments($booking);
        $due = round((float) $pending->sum('amount'), 2);

        if ($due <= 0) {
            return sendError('This booking is already paid in full.');
        }

        $user = $booking->user;
        if (! $user) {
            return sendError('This booking has no customer attached.');
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            return sendError('Online payment is not available right now.');
        }

        // The deposit model's deposit IS Bookboll's fee, so a balance-after-deposit
        // collection is the provider's money only. Tell fulfilment which it is.
        // This page charges the entire outstanding bill. Calling a deposit +
        // remaining bill "deposit" collected both but settled only the deposit.
        $paymentType = $pending->every(fn (Payment $p) => $p->type === \PaymentType::DEPOSIT) ? 'deposit' : 'full';

        try {
            $connectData = app(ProviderStripeService::class)->checkoutData([$booking->id], $paymentType, $due);
            Stripe::setApiKey($secret);
            $user->createOrGetStripeCustomer();

            $returnUrls = $this->stripeService->checkoutReturnUrls();
            $currency = strtolower($this->currencyFor($booking));

            $session = StripeCheckoutSession::create([
                ...($connectData ? ['payment_intent_data'=>$connectData] : []),
                'customer'             => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode'                 => 'payment',
                'success_url'          => $returnUrls['success_url'],
                'cancel_url'           => $returnUrls['cancel_url'],
                'metadata'             => [
                    'user_id'        => $user->id,
                    'booking_id'     => $booking->id,
                    'total_amount'   => $due,
                    'payment_method' => 'online_payment',
                    'booking_number' => $booking->booking_id,
                    'payment_type'   => $paymentType,
                    'source'         => 'pay_link',
                ],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => $currency,
                        'unit_amount'  => (int) round($due * 100),
                        'product_data' => [
                            'name' => 'Booking #' . $booking->booking_id,
                        ],
                    ],
                    'quantity'   => 1,
                ]],
            ]);

            StripeSession::create([
                'session_id'  => $session->id,
                'booking_id'  => $booking->id,
                'booking_ids' => [$booking->id],
                'source'      => 'pay_link',
                'settlement'  => app(ProviderStripeService::class)->snapshot($connectData, $due, $currency),
                'processed'   => false,
            ]);

            return sendSuccess('Checkout session created', [
                'url'        => $session->url,
                'session_id' => $session->id,
                'amount'     => $due,
                'currency'   => strtoupper($currency),
            ]);
        } catch (Exception $e) {
            Log::error('Pay-link checkout failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return sendError('Could not start the payment. Please try again.');
        }
    }

    protected function findBooking(string $uuid): ?Booking
    {
        if (trim($uuid) === '') {
            return null;
        }

        return Booking::with(['user:id,name', 'listing:id,name,venue_id', 'venue', 'payments'])
            ->where('uuid', $uuid)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, Payment> */
    protected function pendingPayments(Booking $booking)
    {
        return $booking->payments
            ->filter(fn (Payment $p) => $p->status === \PaymentStatus::PENDING)
            ->values();
    }

    /**
     * What this booking charges in — its own stamped currency.
     *
     * Booking::currencyForVenue() resolves override -> the venue's COUNTRY
     * currency -> the platform default, and stamps the result on the row at
     * creation. Reading currency_override alone skipped the country step, and
     * config('services.stripe.currency') does not exist, so the expression
     * collapsed to a hard-coded 'QAR': every venue relying on its country —
     * Pakistan resolving to PKR — had its pay link billed in Qatari riyals at
     * the right number. The stored value is also the correct authority on its
     * own terms: it is what the customer was quoted.
     */
    protected function currencyFor(Booking $booking): string
    {
        return $booking->currency
            ?: Booking::currencyForVenue($booking->venue_id);
    }

    /**
     * Only what the payer needs to recognise the booking and the amount — the
     * link is public, so nothing identifying beyond their own first name.
     */
    protected function mapBill(Booking $booking): array
    {
        $pending = $this->pendingPayments($booking);
        $due = round((float) $pending->sum('amount'), 2);
        $total = round((float) ($booking->total_price ?? 0), 2);

        $date = $booking->date;
        if ($date instanceof \Carbon\CarbonInterface) {
            $date = $date->format('Y-m-d');
        }

        return [
            'id'            => $booking->uuid,
            'reference'     => $booking->booking_id,
            'listingName'   => $booking->listing?->name ?: 'Booking',
            'venueName'     => $booking->venue?->name ?: '',
            'venueAddress'  => $booking->venue?->address ?: '',
            'date'          => $date,
            'startTime'     => $this->hm($booking->start_time),
            'endTime'       => $this->hm($booking->end_time),
            'customerName'  => trim(explode(' ', (string) ($booking->user?->name ?: ''))[0] ?? ''),
            'currency'      => strtoupper($this->currencyFor($booking)),
            'total'         => $total,
            'paidSoFar'     => max(0, round($total - $due, 2)),
            'due'           => $due,
            'status'        => $booking->status,
            'paymentStatus' => (string) $booking->payment_status,
            'cancelled'     => $booking->status === \BookingStatus::CANCELLED,
            'payments'      => $pending->map(fn (Payment $p) => [
                'id'       => (string) $p->id,
                'label'    => $this->paymentLabel($p),
                'amount'   => round((float) $p->amount, 2),
                'currency' => strtoupper($this->currencyFor($booking)),
            ])->values()->all(),
        ];
    }

    protected function paymentLabel(Payment $p): string
    {
        return match ($p->type) {
            \PaymentType::DEPOSIT   => 'Deposit',
            \PaymentType::REMAINING => 'Balance on arrival',
            default                 => 'Booking total',
        };
    }

    protected function hm($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
