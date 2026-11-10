<?php
namespace App\Services;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Owner-approved internal attribution for missing legacy splits, never a charge. */
final class HistoricalOwnedFeeReconciliation
{
    public const POLICY = 'owner_confirmed_legacy_20pct';
    public const RESTORED_QUOTE = 'restored_booking_quote';

    public function plan(Payment $payment): ?array
    {
        $booking = $payment->booking;
        $context = $payment->financial_context ?? [];
        $creation = $booking?->creation_context ?? [];
        if (!$booking || ($context['operator'] ?? '') !== 'bookboll'
            || ($context['ownership_basis'] ?? '') !== 'preview_owner_confirmed_2026_11_10'
            || !in_array($creation['channel'] ?? '', ['marketplace','site'], true)
            || ($creation['actor_kind'] ?? '') !== 'customer'
            || in_array($booking->origin, ['manual_admin','manual'], true)
            || $booking->payment_method === 'payment_skipped' || $booking->payment_status === 'payment_skipped'
            || $payment->source_type === 'hosted_game'
            || !in_array($payment->type, ['full','booking','deposit','remaining','balance'], true)
            || $payment->ownedAllocations->isNotEmpty()
            || !$payment->currency || !is_numeric($payment->amount) || $payment->amount < 0) return null;

        if (($context['fee_minor'] ?? null) !== null || ($context['net_fee_minor'] ?? null) !== null
            || $booking->commission_fee_minor !== null || $booking->commission_effective_pct !== null) {
            return $this->savedQuotePlan($payment, $context);
        }

        // Existing retained online deposits have their own recorded-amount rule;
        // this policy fills only the missing legacy customer receipt splits.
        if ($payment->type === 'deposit' && $payment->method === 'online') return null;
        if (in_array($payment->type, ['remaining','balance'], true)
            && ($context['payment_method'] ?? '') === 'deposit_remaining_on_arrival') return null;

        $amount = Money::toMinor($payment->amount, $payment->currency);
        $split = Money::split($amount, 20);
        return [
            ['bucket'=>'platform','amount_minor'=>$split['fee'],'currency'=>$payment->currency,'fee_rate'=>20,'basis'=>self::POLICY],
            ['bucket'=>'venue','amount_minor'=>$split['remainder'],'currency'=>$payment->currency,'fee_rate'=>20,'basis'=>self::POLICY],
        ];
    }

    /** Restore an exact original online quote plus a separately recorded balance. */
    private function savedQuotePlan(Payment $payment, array $context): ?array
    {
        $booking=$payment->booking;
        $fee=$context['net_fee_minor'] ?? $context['fee_minor'] ?? null;
        $merchant=$context['merchant_share_minor'] ?? null;
        $currency=$payment->currency;
        if (!is_numeric($fee) || !is_numeric($merchant) || $fee<0 || $merchant<0
            || ($context['currency'] ?? '')!==$currency || (float)($booking->loyalty_discount ?? 0)!==0.0) return null;
        $siblings=$booking->payments()->with('ownedAllocations')->get();
        if ($siblings->count()!==2 || $siblings->contains(fn($p)=>!in_array($p->type,['full','booking'],true)
            || $p->currency!==$currency || $p->amount<0 || ($p->financial_context['operator']??'')!=='bookboll'
            || $p->ownedAllocations->contains(fn($a)=>$a->basis!==self::RESTORED_QUOTE))) return null;
        $total=$siblings->sum(fn($p)=>Money::toMinor($p->amount,$currency));
        if ($total!==Money::toMinor($booking->total_price,$currency)) return null;
        $online=$siblings->filter(fn($p)=>$p->method==='online' && Money::toMinor($p->amount,$currency)===(int)$fee+(int)$merchant);
        if ($online->count()!==1 || $siblings->where('method','online')->count()!==1) return null;
        $amount=Money::toMinor($payment->amount,$currency);
        $allocatedFee=$online->first()->id===$payment->id ? (int)$fee : 0;
        return array_values(array_filter([
            ['bucket'=>'platform','amount_minor'=>$allocatedFee,'currency'=>$currency,'fee_rate'=>$context['fee_rate']??null,'basis'=>self::RESTORED_QUOTE],
            ['bucket'=>'venue','amount_minor'=>$amount-$allocatedFee,'currency'=>$currency,'fee_rate'=>$context['fee_rate']??null,'basis'=>self::RESTORED_QUOTE],
        ],fn($a)=>$a['bucket']==='venue'||$a['amount_minor']>0));
    }

    public function apply(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::with(['booking','ownedAllocations'])->lockForUpdate()->findOrFail($payment->id);
            $plan = $this->plan($locked);
            if (!$plan || DB::table('payment_allocation_corrections')->where('payment_id',$locked->id)->where('policy',$plan[0]['basis'])->exists()) return false;
            $before = ['payment'=>$locked->getAttributes(), 'internal_allocations'=>$locked->ownedAllocations->toArray()];
            $locked->ownedAllocations()->createMany($plan);
            DB::table('payment_allocation_corrections')->insert([
                'payment_id'=>$locked->id, 'booking_id'=>$locked->booking_id, 'policy'=>$plan[0]['basis'],
                'before'=>json_encode($before),
                'after'=>json_encode(['payment'=>$locked->getAttributes(),'internal_allocations'=>$locked->ownedAllocations()->get()->toArray()]),
                'evidence'=>json_encode(['approved_on'=>'2026-11-11','rate'=>$plan[0]['fee_rate'],'basis'=>$plan[0]['basis'],
                    'scope'=>'Historical own-venue customer bookings; retain original saved quote when available, otherwise approved 20%',
                    'manual_policy'=>'100% venue revenue; excluded from this correction', 'creation_context'=>$locked->booking->creation_context]),
                'created_at'=>now(),
            ]);
            return true;
        });
    }
}
