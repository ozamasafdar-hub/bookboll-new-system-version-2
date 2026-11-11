<?php
namespace App\Support;

/** Corrections change allocation attribution, never the original receipt or owner. */
final class PaymentBulkRules
{
    public static function allows($actor): bool
    {
        return PaymentRefundAccess::allows($actor) && $actor->hasRole('super_admin');
    }

    public static function destinations(array $row): array
    {
        if (($row['row_kind'] ?? '') === 'receipt' || !in_array($row['status'], ['paid','pending'], true)
            || in_array($row['category'], ['unclassified','cancelled_uncollected','owned_unallocated'], true)) return [];
        if ($row['category'] === 'event_fee') return ['event_fee'];
        $online = $row['method'] === 'online';
        if ($row['operator'] === 'bookboll') {
            $venue = $online ? 'owned_online' : 'owned_onsite';
            return $row['channel'] === 'admin' ? [$venue] : ['owned_platform_fee',$venue];
        }
        if ($row['operator'] !== 'provider' || empty($row['business_id'])) return [];
        if ($row['channel'] === 'admin') return ['provider_manual'];
        if (!in_array($row['channel'], ['marketplace','site'], true)) return [];
        return [($row['source_type'] ?? '') === 'subscription' ? 'provider_membership_fee' : 'provider_booking_fee',
            $online ? 'provider_online' : 'provider_arrival'];
    }

    public static function hash(array $row): string
    {
        // Collection dates/status can legitimately change later; financial evidence cannot.
        return hash('sha256', json_encode([$row['id'], (string)($row['source_payment_id'] ?? $row['id']),
            Money::toMinor($row['amount'],$row['currency']), $row['currency'], $row['method'] === 'online',
            $row['type'] ?? null, $row['channel'], $row['operator'], $row['business_id'], $row['category'],
            $row['source_receipt_ids'] ?? [], $row['allocation_basis'] ?? null, $row['fee_rate'] ?? null]));
    }

    public static function assign(array $row, string $category, $auditId): array
    {
        $provider = in_array($category,['provider_online','provider_arrival','provider_manual'],true);
        return array_merge($row, ['category'=>$category,'recipient_kind'=>$provider ? 'provider' : 'bookboll',
            'recipient_label'=>$provider ? ($row['financial_context']['business_name'] ?? $row['recipient_label'])
                : (in_array($category,['owned_online','owned_onsite'],true) ? 'Bookboll venue' : 'Bookboll platform'),
            'fee_rate'=>$row['channel'] === 'admin' ? 0 : null, 'allocation_basis'=>'superadmin_correction',
            'bulk_correction_id'=>$auditId]);
    }

    public static function apply(array $rows, array $payments): array
    {
        $sources = array_column($payments,null,'id');
        return array_map(function ($row) use ($sources) {
            $correction = $sources[$row['source_payment_id'] ?? $row['id']]['financial_context']['allocation_corrections'][$row['id']] ?? null;
            if (!$correction) return $row;
            if (($correction['state_hash'] ?? '') !== self::hash($row)
                || !in_array($correction['category'],self::destinations(array_merge($row,['status'=>'paid'])),true)) {
                return array_merge($row,['category'=>'unclassified','recipient_kind'=>'unknown','recipient_label'=>'Needs review',
                    'review_reason'=>'Evidence changed after a superadmin allocation correction. Review the original payment.']);
            }
            return self::assign($row,$correction['category'],$correction['audit_id']);
        },$rows);
    }
}
