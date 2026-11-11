<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Independent, read-only arithmetic check of persisted internal allocations. */
final class StoredPaymentAllocationCheck
{
    public static function inspect(): array
    {
        $checked = 0;
        $invalid = 0;
        $groups = DB::table('payment_allocations')->select('payment_id')
            ->selectRaw('SUM(amount_minor) AS allocated_minor, MIN(currency) AS min_currency, MAX(currency) AS max_currency, COUNT(*) AS allocation_count, COUNT(DISTINCT bucket) AS bucket_count')
            ->selectRaw("SUM(CASE WHEN bucket NOT IN ('platform','venue') OR amount_minor < 0 THEN 1 ELSE 0 END) AS invalid_rows")
            ->selectRaw("SUM(CASE WHEN bucket = 'platform' THEN amount_minor ELSE 0 END) AS platform_minor")
            ->groupBy('payment_id');
        DB::query()->fromSub($groups, 'a')->leftJoin('payments as p','p.id','=','a.payment_id')
            ->leftJoin('bookings as b','b.id','=','p.booking_id')
            ->select('a.*','p.id as receipt_id','p.amount','p.currency')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(b.creation_context, '$.channel')) AS creator_channel")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(p.financial_context, '$.channel')) AS payment_channel")
            ->orderBy('a.payment_id')->chunk(1000, function ($rows) use (&$checked, &$invalid) {
                foreach ($rows as $row) {
                    $checked++;
                    $manual = $row->creator_channel === 'admin' || $row->payment_channel === 'admin';
                    $valid = $row->receipt_id && $row->currency && $row->amount >= 0
                        && (int) $row->invalid_rows === 0 && (int) $row->allocation_count === (int) $row->bucket_count
                        && $row->min_currency === $row->currency && $row->max_currency === $row->currency
                        && (int) $row->allocated_minor === Money::toMinor($row->amount, $row->currency)
                        && (!$manual || (int) $row->platform_minor === 0);
                    if (!$valid) $invalid++;
                }
            });
        return ['checked_receipts'=>$checked,'invalid_receipts'=>$invalid];
    }
}
