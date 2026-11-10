<?php

namespace App\Support;

/**
 * Which historical payments a venue transfer may reconcile, and why not.
 *
 * The model readmeAlamgir confirms: external providers could not collect
 * through the system, so Bookboll took the online deposit and KEPT it as its
 * platform fee, and the customer paid the remainder in cash directly to the
 * provider at the facility — cash that was usually never marked paid here.
 *
 * Reconciling says both of those things out loud. It is a reviewed assertion
 * about what already happened, not a payment: it creates no Bookboll receipt,
 * no provider payable, no wallet credit and no payout.
 *
 * Every exclusion below exists because including it would assert something
 * nobody can actually know.
 */
class LegacyReconciliation
{
    public const KIND_DEPOSIT_TO_FEE  = 'deposit_to_fee';
    public const KIND_PROVIDER_CASH   = 'provider_direct_cash';
    public const KIND_PROVIDER_RECEIPT = 'provider_recorded_receipt';
    public const KIND_PROVIDER_PENDING = 'provider_pending';

    /**
     * Why this payment cannot be reconciled, or null if it can.
     *
     * @param  object  $payment  a payments row
     * @param  object  $booking  its booking
     */
    public static function exclusionFor(object $payment, ?object $booking): ?string
    {
        if (! $booking) {
            return 'The booking behind this payment is missing.';
        }

        // A provider reserving their own court was never a marketplace sale,
        // so there is no deposit that was a fee and no arrival cash owed.
        // Read from record_purpose where it exists, falling back to the old
        // inference for rows written before that column.
        if (! BookingPurpose::isSale($booking)) {
            return ($booking->record_purpose ?? null) === BookingPurpose::REVIEW
                ? 'Slot block carrying a posted payment — needs manual review.'
                : 'Provider slot block — never a marketplace sale.';
        }

        if (in_array($booking->status ?? '', ['cancelled'], true)
            || ($booking->payment_status ?? null) === 'cancelled') {
            return 'Booking was cancelled.';
        }

        if (in_array($payment->status ?? '', ['refunded', 'cancelled'], true)) {
            return 'Payment was refunded or cancelled.';
        }

        // Money already attributed to somebody must not be reassigned by a
        // transfer — that would move a settled amount to a new party.
        if (! empty($payment->collected_by_business_id)) {
            return 'Already settled to a business.';
        }
        if (! empty($payment->reconciliation_batch_id)) {
            return 'Already reconciled by an earlier transfer.';
        }

        // A deposit already recorded as commission is a fee; charging a second
        // one on top of it would invent revenue.
        if (in_array($payment->type ?? '', ['booking_commission', 'booking_earning',
            'subscription_commission', 'subscription_earning'], true)) {
            return 'Already classified as commission or earning.';
        }

        if ((float) ($payment->amount ?? 0) <= 0) {
            return 'Zero or negative amount.';
        }

        if (empty($payment->currency)) {
            // Reconciling into "unknown currency" is not a fact about money.
            return 'No currency recorded.';
        }

        return null;
    }

    /**
     * What reconciling this payment would assert.
     *
     * A PAID deposit was Bookboll's fee. An unpaid arrival balance was cash the
     * provider collected directly — which is only asserted after an admin
     * confirms it, because nobody can see that cash from here.
     */
    public static function kindFor(object $payment, ?object $booking = null): ?string
    {
        $type   = (string) ($payment->type ?? '');
        $status = (string) ($payment->status ?? '');
        $online = ($payment->method ?? '') === 'online';
        $manual = PaymentReportRules::channel($booking instanceof \Illuminate\Database\Eloquent\Model
            ? $booking->getAttributes() : (array) $booking) === 'admin';

        if ($type === 'deposit' && $status === 'paid' && $online && !$manual) {
            return self::KIND_DEPOSIT_TO_FEE;
        }

        if ($manual && in_array($type, ['deposit','remaining','full','balance'], true)
            && in_array($status, ['pending','unpaid'], true)) return self::KIND_PROVIDER_PENDING;

        if (in_array($type, ['remaining', 'full'], true)
            && !$online && in_array($payment->method ?? '', ['cash', 'card', 'credit_card'], true)
            && in_array($status, ['pending', 'unpaid'], true)) {
            return self::KIND_PROVIDER_CASH;
        }

        if (in_array($type, ['deposit', 'remaining', 'full', 'balance'], true)
            && $status === 'paid' && (!$online || $manual)) return self::KIND_PROVIDER_RECEIPT;

        return null;
    }

    /**
     * Whether this kind changes what Bookboll earned.
     *
     * A deposit reclassified as a fee is revenue Bookboll already held and now
     * names correctly, so earnings move. Provider cash never was Bookboll's,
     * so it does not — which is why the preview reports a CHANGE rather than a
     * new total: the two must not be confused.
     */
    public static function affectsEarnings(string $kind): bool
    {
        return $kind === self::KIND_DEPOSIT_TO_FEE;
    }
}
