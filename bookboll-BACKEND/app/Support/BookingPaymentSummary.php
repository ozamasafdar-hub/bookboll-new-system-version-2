<?php
namespace App\Support;

use App\Models\Booking;

/** Read-only receipt status; never overwrites the original booking state. */
final class BookingPaymentSummary
{
    public static function fromBooking(Booking $booking): ?array
    {
        $booking->loadMissing('payments.ownedAllocations');
        $payments = $booking->payments;
        if ($payments->isEmpty()) return null;
        $data = $payments->map(fn ($p) => array_merge($p->getAttributes(), [
            'financial_context'=>$p->financial_context ?? [], 'owned_allocations'=>$p->ownedAllocations->toArray(),
        ]))->all();
        $rows = array_filter(PaymentReportRules::rows($data, array_merge($booking->getAttributes(), [
            'financial_context'=>$booking->financial_context ?? [],
            'creation_context'=>$booking->creation_context ?? [],
        ])), fn ($row) => $row['row_kind'] !== 'receipt');
        $currencies = array_values(array_unique(array_column($rows, 'currency')));
        if (count($currencies) !== 1 || !$currencies[0]) return null;
        $currency = $currencies[0]; $paid = 0; $pending = 0; $review = false; $refunded = false;
        foreach ($rows as $row) {
            if ($row['category'] === 'unclassified') { $review = true; continue; }
            if ($row['status'] === 'paid') $paid += Money::toMinor($row['amount'], $currency);
            if ($row['status'] === 'pending') $pending += Money::toMinor($row['amount'], $currency);
            if ($row['status'] === 'refunded') $refunded = true;
        }
        $status = $review ? 'Needs review' : ($refunded ? 'Refunded / adjusted' :
            ($paid >= Money::toMinor($booking->total_price, $currency) && !$pending ? 'Paid' : ($paid > 0 ? 'Part paid' : 'Pending')));
        return ['currency'=>$currency, 'paid'=>$review ? null : Money::toMajor($paid,$currency),
            'pending'=>$review ? null : Money::toMajor($pending,$currency), 'status'=>$status];
    }
}
