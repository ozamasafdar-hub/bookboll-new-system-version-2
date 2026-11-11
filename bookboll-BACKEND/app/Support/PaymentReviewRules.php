<?php
namespace App\Support;

/** An explicit report attribution, never a payment, refund or collection. */
final class PaymentReviewRules
{
    public static function hash(array $payments, array $booking): string
    {
        $facts = [];
        foreach ($payments as $p) {
            $context = $p['financial_context'] ?? [];
            unset($context['review_decision'], $context['allocation_corrections']);
            $facts[] = array_intersect_key($p, array_flip(['id','booking_id','subscription_id','type','amount','currency','method','status',
                'source_type','recipient','paid_at','date','charge_id','payment_intent_id','payment_refund_id','marked_paid_by','reconciliation_batch_id']))
                + ['financial_context'=>$context, 'owned_allocations'=>$p['owned_allocations'] ?? []];
        }
        usort($facts, fn ($a,$b) => (int)$a['id'] <=> (int)$b['id']);
        $b = array_intersect_key($booking, array_flip(['id','venue_id','user_id','status','payment_status','payment_method','origin',
            'commission_channel','commission_effective_pct','creation_context','financial_context','checkout_reference']));
        $sort = function ($value) use (&$sort) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($sort,$value);
        };
        return hash('sha256', json_encode($sort([$facts,$b]), JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function options(array $payment, array $booking, array $siblings, array $row): array
    {
        if (($row['category'] ?? '') !== 'unclassified') return [];
        $options = [];
        $validAmount = preg_match('/^[A-Z]{3}$/D', $payment['currency'] ?? '') && is_numeric($payment['amount'] ?? null) && $payment['amount'] >= 0;
        if (!$validAmount) return [];
        // A cancelled booking is not proof that a previously collected receipt was unpaid.
        if ($row['status'] === 'cancelled' && in_array($payment['status'], ['cancelled','pending','unpaid'],true)
            && empty($payment['paid_at']) && empty($payment['marked_paid_by']) && empty($payment['charge_id'])
            && empty($payment['payment_intent_id']) && empty($payment['payment_refund_id'])
            && empty($payment['owned_allocations']) && empty($payment['stripe_receipt_url']) && empty($payment['stripe_invoice_url'])
            && count($siblings) === 1) $options[] = 'cancelled_uncollected';
        $simpleReceipt = count(array_filter($siblings, fn ($p) => in_array($p['type'] ?? '',
            ['booking_commission','booking_earning','subscription_commission','subscription_earning'],true))) === 0;
        if ($row['operator'] === 'provider' && in_array($row['channel'],['marketplace','site'],true)
            && ($booking['creation_context']['actor_kind'] ?? '') === 'customer' && !empty($row['business_id'])
            && ($payment['method'] ?? '') === 'online' && in_array($payment['type'] ?? '',['full','booking'],true)
            && $payment['status'] === 'paid' && Money::toMinor($payment['amount'],$payment['currency']) > 0
            && empty($payment['payment_refund_id']) && empty($payment['owned_allocations'])
            && !in_array('payment_skipped', [$booking['payment_method'] ?? '',$booking['payment_status'] ?? ''],true) && $simpleReceipt) {
            $options[] = 'retained_deposit';
            $options[] = 'provider_split';
        }
        return $options;
    }

    public static function apply(array $rows, array $payments, array $booking): array
    {
        if (!array_filter($payments, fn ($p) => !empty($p['financial_context']['review_decision']))) return $rows;
        $hash = self::hash($payments,$booking);
        $result = [];
        foreach ($rows as $row) {
            $p = current(array_filter($payments, fn ($p) => (string)$p['id'] === (string)($row['source_payment_id'] ?? $row['id'])));
            $decision = $p['financial_context']['review_decision'] ?? null;
            if (!$decision || ($decision['state_hash'] ?? '') !== $hash
                || !in_array($decision['action'] ?? '', self::options($p,$booking,$payments,$row),true)) {
                $result[] = $row; continue;
            }
            $row['review_reason'] = null;
            $row['review_decision_id'] = $decision['id'];
            $row['allocation_basis'] = 'admin_review';
            if ($decision['action'] === 'cancelled_uncollected') {
                $result[] = array_merge($row,['category'=>'cancelled_uncollected','recipient_kind'=>'none','recipient_label'=>'No money received']);
            } elseif ($decision['action'] === 'retained_deposit') {
                $result[] = array_merge($row,['category'=>'provider_booking_fee','recipient_kind'=>'bookboll','recipient_label'=>'Bookboll platform','fee_rate'=>null]);
            } else {
                $total = Money::toMinor($p['amount'],$p['currency']);
                $fee = $decision['fee_minor'] ?? null;
                if (!is_int($fee) || $fee < 0 || $fee > $total) { $result[] = array_merge($row,['review_reason'=>'The reviewed fee split is invalid. Review this payment again.']); continue; }
                foreach (['platform'=>$fee, 'provider'=>$total-$fee] as $bucket=>$amount) {
                    if (!$amount) continue;
                    $result[] = array_merge($row,['id'=>$p['id'].':review_'.$bucket,'source_payment_id'=>(string)$p['id'],
                        'source_receipt_ids'=>[$p['id']], 'receipt_amount'=>$p['amount'], 'amount'=>Money::toMajor($amount,$p['currency']),
                        'category'=>$bucket === 'platform' ? 'provider_booking_fee' : 'provider_online',
                        'recipient_kind'=>$bucket === 'platform' ? 'bookboll' : 'provider',
                        'recipient_label'=>$bucket === 'platform' ? 'Bookboll platform' : ($p['financial_context']['business_name'] ?? 'External provider'),
                        'fee_rate'=>null]);
                }
            }
        }
        return $result;
    }
}
