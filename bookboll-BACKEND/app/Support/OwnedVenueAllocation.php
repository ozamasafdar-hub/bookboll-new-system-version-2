<?php

namespace App\Support;

/** Internal attribution only. Never charges a customer or transfers funds. */
final class OwnedVenueAllocation
{
    public static function plan(array $payment, array $context): ?array
    {
        if (($context['operator'] ?? '') !== 'bookboll') return null;
        if (!in_array($payment['type'] ?? '', ['full', 'booking', 'deposit', 'remaining', 'balance'], true)) return null;
        if (($context['payment_method'] ?? '') === 'payment_skipped') return null;
        $currency = $payment['currency'] ?? '';
        if (!$currency || !is_numeric($payment['amount'] ?? null) || $payment['amount'] < 0) return null;
        $amount = Money::toMinor($payment['amount'], $currency);
        $channel = $context['channel'] ?? 'unknown';
        $type = $payment['type'];
        if ($channel === 'admin') {
            $fee = 0;
        } elseif (!in_array($channel, ['marketplace', 'site'], true)) {
            return null;
        } elseif ($type === 'deposit' && ($payment['method'] ?? '') === 'online') {
            // The actual retained deposit is the platform fee, including any
            // recorded discount. Never charge another fee on its arrival balance.
            $fee = $amount;
        } elseif (in_array($type, ['remaining', 'balance'], true)
            && ($context['payment_method'] ?? '') === 'deposit_remaining_on_arrival') {
            $fee = 0;
        } else {
            // Full online OR full arrival: the frozen quote, not today's rate.
            $fee = $context['net_fee_minor'] ?? $context['fee_minor'] ?? null;
            $total = $context['total_minor'] ?? null;
            if (!is_numeric($fee) || !is_numeric($total) || (int) $total !== $amount
                || ($context['currency'] ?? '') !== $currency || $fee < 0 || $fee > $amount) return null;
            $fee = (int) $fee;
        }
        $rows = [];
        if ($fee > 0) $rows[] = ['bucket'=>'platform', 'amount_minor'=>$fee];
        if ($amount - $fee > 0 || !$rows) $rows[] = ['bucket'=>'venue', 'amount_minor'=>$amount - $fee];
        return array_map(fn ($row) => $row + [
            'currency'=>$currency, 'fee_rate'=>$channel === 'admin' ? 0 : ($context['fee_rate'] ?? null),
            'basis'=>$channel === 'admin' ? 'manual_no_fee' : ($type === 'deposit' ? 'retained_deposit' : 'booking_quote'),
        ], $rows);
    }

    /** Expand only saved allocations. Missing/changed history remains reviewable. */
    public static function expand(array $row): array
    {
        if ($row['operator'] !== 'bookboll' || !in_array($row['category'], ['owned_online','owned_onsite'], true)) return [$row];
        $allocations = $row['owned_allocations'] ?? [];
        $amount = Money::toMinor($row['amount'], $row['currency']);
        $buckets = array_column($allocations, 'bucket');
        $valid = $allocations && count(array_unique($buckets)) === count($buckets)
            && count(array_filter($allocations, fn ($a) => in_array($a['bucket'], ['platform','venue'], true)
                && $a['currency'] === $row['currency'] && is_numeric($a['amount_minor']) && $a['amount_minor'] >= 0)) === count($allocations)
            && array_sum(array_column($allocations, 'amount_minor')) === $amount;
        if (!$valid) {
            // Manual bookings explicitly have no internal platform fee.
            if (!$allocations && $row['channel'] === 'admin') return [$row];
            if (!$allocations && ($row['financial_context']['ownership_basis'] ?? '') === 'preview_owner_confirmed_2026_11_10') {
                return [array_merge($row, ['category'=>'owned_unallocated', 'recipient_kind'=>'bookboll',
                    'recipient_label'=>'Bookboll · split needs review',
                    'review_reason'=>'Bookboll ownership is confirmed, but this historical receipt has no saved internal fee split.'])];
            }
            return [array_merge($row, ['category'=>'unclassified', 'recipient_kind'=>'unknown', 'recipient_label'=>'Needs review',
                'review_reason'=>'The platform/venue split has not been recorded or no longer matches this receipt.'])];
        }
        return array_map(fn ($a) => array_merge($row, [
            'id'=>$row['id'].':'.$a['bucket'], 'source_payment_id'=>$row['id'],
            'allocation_id'=>$a['id'] ?? null, 'receipt_amount'=>$row['amount'],
            'source_receipt_ids'=>[$row['id']],
            'amount'=>Money::toMajor((int) $a['amount_minor'], $row['currency']),
            'category'=>$a['bucket'] === 'platform' ? 'owned_platform_fee' : $row['category'],
            'recipient_label'=>$a['bucket'] === 'platform' ? 'Bookboll platform' : 'Bookboll venue',
            'fee_rate'=>$a['fee_rate'], 'allocation_basis'=>$a['basis'],
        ]), $allocations);
    }
}
