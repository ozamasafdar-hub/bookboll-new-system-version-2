<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Payment;
use BookingPaymentStatus;
use BookingStatus;
use PaymentStatus;
use PaymentType;

/**
 * The one place a booking is brought in line with its payments.
 *
 * Before this, every path that marked a payment paid re-implemented the same
 * three steps by hand — derive payment_status, confirm the booking, regenerate
 * the invoice — and each forgot a different one. The dashboard collect path
 * never regenerated the invoice; the mobile mark-paid only did when fully
 * paid; mark-received only when a client ticked a box. A merchant who
 * collected a balance in cash kept an invoice saying "remaining due".
 *
 * Now PaymentObserver raises PaymentStatusChanged and the listener calls
 * run(). Idempotent throughout: safe to call any number of times per request.
 */
final class BookingPaymentSync
{
    /** The customer's own money. Split-ledger rows share the booking but are not receipts. */
    public const CUSTOMER_TYPES = [PaymentType::FULL, PaymentType::DEPOSIT, PaymentType::REMAINING];

    /**
     * @var array<int, string> booking id => fingerprint of its customer payments
     *                         at the moment its invoice was last (re)generated
     */
    private array $invoiced = [];

    public static function concerns(Payment $payment): bool
    {
        return $payment->booking_id !== null
            && in_array($payment->type, self::CUSTOMER_TYPES, true);
    }

    /** What the customer has actually paid. Never counts the commission/earning split. */
    public static function collected(Booking $booking): float
    {
        return (float) $booking->payments()
            ->whereIn('type', self::CUSTOMER_TYPES)
            ->where('status', PaymentStatus::PAID)
            ->sum('amount');
    }

    /** The payment_status the receipts imply, or null to leave it alone. */
    public static function derivePaymentStatus(Booking $booking): ?string
    {
        // Deliberate states money must not overwrite.
        if (in_array($booking->payment_status, [BookingPaymentStatus::CANCELLED, BookingPaymentStatus::PAYMENT_SKIPPED], true)
            || $booking->status === BookingStatus::CANCELLED) {
            return null;
        }

        $total = (float) $booking->total_price;
        if ($total <= 0) {
            return null;
        }

        $collected = self::collected($booking);

        if ($collected >= $total) {
            return BookingPaymentStatus::FULLY_PAID;
        }
        if ($collected > 0) {
            // The enum has no "partially paid"; this is the name it uses.
            return BookingPaymentStatus::DEPOSIT_PAID;
        }

        return BookingPaymentStatus::PENDING;
    }

    /** Write the derived status only when it differs. Returns true when it wrote. */
    public static function syncPaymentStatus(Booking $booking): bool
    {
        $status = self::derivePaymentStatus($booking);
        if ($status === null) {
            return false;
        }

        $fullyPaid = $status === BookingPaymentStatus::FULLY_PAID;
        if ($booking->payment_status === $status && (bool) $booking->full_payment_received === $fullyPaid) {
            return false;
        }

        $booking->payment_status        = $status;
        $booking->full_payment_received = $fullyPaid;
        $booking->save();

        return true;
    }

    /** Status, confirmation, invoice — everything a payment change implies for its booking. */
    public function run(Booking $booking): void
    {
        // Deliberate states a payment must not disturb: a cancelled booking,
        // and one whose payment was skipped on purpose.
        if ($booking->status === BookingStatus::CANCELLED
            || in_array($booking->payment_status, [BookingPaymentStatus::CANCELLED, BookingPaymentStatus::PAYMENT_SKIPPED], true)) {
            return;
        }

        self::syncPaymentStatus($booking);

        // Confirming regenerates the invoice itself and emails it. When the
        // booking was already confirmed (a balance collected later, a deposit
        // marked paid), the invoice still has to reflect the new payment.
        if (! autoConfirmBookingIfPaid($booking) && ! $this->invoiceIsCurrent($booking)) {
            regenerateBookingInvoice($booking);
        }
    }

    /**
     * Record that the invoice now reflects this exact set of payments.
     *
     * Several rows flipped in one transaction raise one event each after
     * commit; they all see the same final payments, so only the first
     * regenerates. A later, different change (a balance after a deposit, a
     * cancellation) has a new fingerprint and regenerates again.
     */
    public function markInvoiced(Booking $booking): void
    {
        $this->invoiced[$booking->id] = self::fingerprint($booking);
    }

    public function invoiceIsCurrent(Booking $booking): bool
    {
        return ($this->invoiced[$booking->id] ?? null) === self::fingerprint($booking);
    }

    private static function fingerprint(Booking $booking): string
    {
        return md5(json_encode(
            $booking->payments()
                ->whereIn('type', self::CUSTOMER_TYPES)
                ->orderBy('id')
                ->get(['id', 'status', 'amount'])
                ->map(fn (Payment $p) => [$p->id, $p->status, (string) $p->amount])
                ->all()
        ));
    }
}
