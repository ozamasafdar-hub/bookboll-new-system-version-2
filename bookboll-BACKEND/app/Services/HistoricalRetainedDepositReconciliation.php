<?php
namespace App\Services;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Three specific receipts confirmed by the owner, not a general full-online rule. */
final class HistoricalRetainedDepositReconciliation
{
    public const POLICY = 'owner_confirmed_retained_deposit_2026_11_11';
    public const RECEIPTS = [
        'PAY-2723-FUL-066381' => ['booking_id'=>2723, 'amount_minor'=>14900],
        'PAY-2722-FUL-055751' => ['booking_id'=>2722, 'amount_minor'=>14900],
        'PAY-1863-FUL-820143' => ['booking_id'=>1863, 'amount_minor'=>15000],
    ];

    public function eligible(Payment $payment): bool
    {
        $expected = self::RECEIPTS[$payment->payment_id] ?? null;
        $booking = $payment->booking;
        $creation = $booking?->creation_context ?? [];
        return $expected && $booking
            && (int)$payment->booking_id === $expected['booking_id']
            && $payment->currency === 'QAR'
            && Money::toMinor($payment->amount, 'QAR') === $expected['amount_minor']
            && $payment->status === 'paid' && $payment->method === 'online' && $payment->type === 'full'
            && ($payment->financial_context['operator'] ?? '') === 'provider'
            && in_array($creation['channel'] ?? '', ['marketplace','site'], true)
            && ($creation['actor_kind'] ?? '') === 'customer'
            && !in_array($booking->origin, ['manual_admin','manual'], true)
            && $booking->payment_method !== 'payment_skipped' && $booking->payment_status !== 'payment_skipped'
            && $payment->source_type !== 'hosted_game' && $payment->ownedAllocations->isEmpty();
    }

    public function apply(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::with(['booking','ownedAllocations'])->lockForUpdate()->findOrFail($payment->id);
            if (!$this->eligible($locked) || DB::table('payment_allocation_corrections')
                ->where('payment_id',$locked->id)->where('policy',self::POLICY)->exists()) return false;
            $before = $locked->getAttributes();
            $context = array_merge($locked->financial_context ?? [], [
                'reconciliation_kind'=>'deposit_to_fee', 'correction_basis'=>self::POLICY,
            ]);
            Payment::withoutTimestamps(fn()=> $locked->forceFill([
                'type'=>'deposit', 'recipient'=>'@bookboll', 'financial_context'=>$context,
                'reconciliation_batch_id'=>self::POLICY,
            ])->saveQuietly());
            DB::table('payment_allocation_corrections')->insert([
                'payment_id'=>$locked->id, 'booking_id'=>$locked->booking_id, 'policy'=>self::POLICY,
                'before'=>json_encode(['payment'=>$before]),
                'after'=>json_encode(['payment'=>$locked->getAttributes()]),
                'evidence'=>json_encode([
                    'approved_on'=>'2026-11-11', 'confirmation'=>'They were deposits kept by Bookboll',
                    'receipt_reference'=>$locked->payment_id, 'currency'=>'QAR',
                    'retained_amount_minor'=>self::RECEIPTS[$locked->payment_id]['amount_minor'],
                    'creation_context'=>$locked->booking->creation_context,
                    'scope'=>'These three paid external-provider receipts only; no provider share or additional charge',
                ]),
                'created_at'=>now(),
            ]);
            return true;
        });
    }
}
