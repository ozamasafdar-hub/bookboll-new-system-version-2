<?php

namespace App\Support;

/** A receipt split, not another payment or a provider wallet credit. */
final class ProviderOnlineAllocation
{
    public static function plan(array $payment, array $context): ?array
    {
        if (($context['operator'] ?? '') !== 'provider' || empty($context['business_id'])
            || !in_array($context['channel'] ?? '', ['marketplace','site'], true)
            || !in_array($payment['type'] ?? '', ['full','booking'], true)
            || ($payment['method'] ?? '') !== 'online') return null;
        $currency = $payment['currency'] ?? '';
        $fee = $context['net_fee_minor'] ?? null;
        $total = $context['total_minor'] ?? null;
        if (!$currency || ($context['currency'] ?? '') !== $currency
            || !is_numeric($payment['amount'] ?? null) || $payment['amount'] < 0
            || !is_numeric($fee) || !is_numeric($total)
            || (int)$total !== Money::toMinor($payment['amount'], $currency)
            || $fee < 0 || $fee > $total) return null;
        return array_map(fn($row) => $row + [
            'currency'=>$currency, 'fee_rate'=>$context['fee_rate'] ?? null, 'basis'=>'provider_booking_quote',
        ], [
            ['bucket'=>'platform','amount_minor'=>(int)$fee],
            ['bucket'=>'venue','amount_minor'=>(int)$total - (int)$fee],
        ]);
    }

    public static function expand(array $row): array
    {
        // A conserving legacy pair already accounts for its parent receipt.
        // Only expand otherwise unallocated full-online provider receipts.
        if ($row['row_kind'] === 'receipt' || $row['category'] !== 'unclassified'
            || $row['operator'] !== 'provider' || $row['method'] !== 'online'
            || !in_array($row['payment_type'], ['full','booking'], true)
            || !in_array($row['channel'], ['marketplace','site'], true)) return [$row];
        $allocations = $row['owned_allocations'] ?? [];
        if (!$allocations) return [$row];
        $buckets = array_column($allocations, 'bucket');
        $valid = count($allocations) === 2 && count(array_unique($buckets)) === 2
            && count(array_filter($allocations, fn($a) => in_array($a['bucket'], ['platform','venue'], true)
                && $a['currency'] === $row['currency'] && is_numeric($a['amount_minor']) && $a['amount_minor'] >= 0)) === 2
            && array_sum(array_column($allocations, 'amount_minor')) === Money::toMinor($row['amount'], $row['currency']);
        if (!$valid) return [$row];
        return array_map(fn($a) => array_merge($row, [
            'id'=>$row['id'].':'.$a['bucket'], 'source_payment_id'=>$row['id'],
            'allocation_id'=>$a['id'] ?? null, 'receipt_amount'=>$row['amount'], 'source_receipt_ids'=>[$row['id']],
            'amount'=>Money::toMajor((int)$a['amount_minor'], $row['currency']),
            'category'=>$a['bucket'] === 'platform' ? 'provider_booking_fee' : 'provider_online',
            'recipient_kind'=>$a['bucket'] === 'platform' ? 'bookboll' : 'provider',
            'recipient_label'=>$a['bucket'] === 'platform' ? 'Bookboll platform' : ($row['financial_context']['business_name'] ?? 'External provider'),
            'fee_rate'=>$a['fee_rate'], 'allocation_basis'=>$a['basis'], 'review_reason'=>null,
        ]), $allocations);
    }
}
