<?php
namespace App\Support;

/** Shared filter predicate, applied after receipt reconciliation. */
final class PaymentReportFilter
{
    public static function matches(array $row, array $filters): bool
    {
        foreach (['status'=>'status','method'=>'method','category'=>'category','channel'=>'channel','currency'=>'currency','source'=>'source','location_id'=>'venue_id','business_id'=>'business_id'] as $filter => $key) {
            $values = $filters[$filter] ?? null;
            if ($values === null || $values === '' || $values === []) continue;
            $values = is_array($values) ? array_map('strval', $values) : explode(',', (string) $values);
            $actual = $key === 'method' && ($row[$key] ?? '') === 'credit_card' ? 'card' : (string) ($row[$key] ?? '');
            if (!in_array($actual, $values, true)) return false;
        }
        $scope = $filters['scope'] ?? 'all';
        if ($scope === 'review' && $row['category'] !== 'unclassified') return false;
        if ($scope === 'fee_split' && $row['category'] !== 'owned_unallocated') return false;
        if (in_array($scope, ['bookboll','provider'], true) && $row['recipient_kind'] !== $scope) return false;
        $date = $row['payment_date'];
        if (!empty($filters['from_date']) && $date < $filters['from_date']) return false;
        if (!empty($filters['to_date']) && $date > $filters['to_date']) return false;
        foreach (['amount_min','amount_max'] as $key) {
            if (!isset($filters[$key]) || $filters[$key] === '') continue;
            if ($key === 'amount_min' && (float) $row['amount'] < (float) $filters[$key]) return false;
            if ($key === 'amount_max' && (float) $row['amount'] > (float) $filters[$key]) return false;
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $haystack = implode(' ', array_map(fn ($key) => (string) ($row[$key] ?? ''), ['payment_id','booking_ref','booking_id','customer_name','customer_email','venue_name','listing_name','recipient_label','created_by_name']));
            $position = function_exists('mb_stripos') ? mb_stripos($haystack, $search) : stripos($haystack, $search);
            if ($position === false) return false;
        }
        return true;
    }
}
