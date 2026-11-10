<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Support\OwnedVenueAllocation;
use Illuminate\Support\Facades\DB;

/** Audited attribution correction. Amounts, statuses, payment methods and dates never change. */
final class BookingCreatorReconciliation
{
    public function apply(Booking $booking, array $creation): void
    {
        if (($booking->creation_context['version'] ?? null) === 1) return;
        DB::transaction(function () use ($booking, $creation) {
            $before = $booking->getAttributes();
            $booking->creation_context = $creation;
            $manual = $creation['channel'] === 'admin';
            if ($manual) {
                $booking->origin = 'manual_admin';
                $booking->commission_effective_pct = 0;
                $booking->commission_fee_minor = 0;
                $booking->payment_breakdown = array_merge($booking->payment_breakdown ?? [], ['commission_amount'=>0]);
                $booking->financial_context = $this->manualContext($booking->financial_context ?? [], $creation);
            }
            Booking::withoutTimestamps(fn () => $booking->saveQuietly());
            if (!$manual) return;
            $this->audit($booking->id, null, $before, $booking->getAttributes(), $creation);
            foreach ($booking->payments as $payment) {
                if ($payment->source_type === 'hosted_game') continue; // Separate event-host fee.
                $prior = $payment->getAttributes() + ['internal_allocations'=>$payment->ownedAllocations->toArray()];
                $context = $this->manualContext($payment->financial_context ?: ($booking->financial_context ?? []), $creation);
                // An audited transfer renamed this original deposit; restore its
                // receipt type. Ordinary linked split rows keep their linkage.
                if ($payment->type === 'booking_commission' && ($context['reconciliation_kind'] ?? '') === 'deposit_to_fee'
                    && $payment->reconciliation_batch_id) $payment->type = 'deposit';
                $payment->financial_context = $context;
                if (($context['operator'] ?? '') === 'bookboll') {
                    $payment->recipient = '@bookboll';
                    $payment->ownedAllocations()->delete();
                    $plan = OwnedVenueAllocation::plan($payment->getAttributes(), $context);
                    if ($plan) $payment->ownedAllocations()->createMany($plan);
                } elseif (($context['operator'] ?? '') === 'provider') {
                    $payment->recipient = $context['provider_recipient'] ?? $payment->recipient;
                    $payment->ownedAllocations()->delete();
                }
                Payment::withoutTimestamps(fn () => $payment->saveQuietly());
                $payment->load('ownedAllocations');
                $this->audit($booking->id, $payment->id, $prior,
                    $payment->getAttributes() + ['internal_allocations'=>$payment->ownedAllocations->toArray()], $creation);
            }
        });
    }

    private function manualContext(array $context, array $creation): array
    {
        return array_merge($context, ['channel'=>'admin', 'fee_rate'=>0, 'fee_minor'=>0, 'net_fee_minor'=>0,
            'channel_basis'=>$creation['basis'], 'creator_id'=>$creation['actor_id'],
            'creator_correction_version'=>1]);
    }

    private function audit(int $bookingId, ?int $paymentId, array $before, array $after, array $evidence): void
    {
        DB::table('booking_creator_corrections')->insert(['booking_id'=>$bookingId, 'payment_id'=>$paymentId,
            'before'=>json_encode($before), 'after'=>json_encode($after), 'evidence'=>json_encode($evidence), 'created_at'=>now()]);
    }
}
