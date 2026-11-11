<?php
namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cache derived reporting data, never receipts. Writes invalidate after commit. */
final class PaymentReportCache
{
    private const TABLES = ['payments','payment_allocations','bookings','venues','listings','users',
        'businesses','business_memberships','class_subscriptions','class_programs','model_has_roles','user_venue','payment_refunds','payment_review_decisions'];

    private static function prefix(): string
    {
        return 'payment-report-v12:'.hash('sha256', DB::connection()->getDatabaseName());
    }

    public static function version(): string
    {
        return (string) Cache::rememberForever(self::prefix().':version', fn () => (string) Str::uuid());
    }

    public static function remember(string $scope, string $part, callable $build, bool $refresh = false): array
    {
        // A transaction must see its own writes and must not publish uncommitted data.
        if (DB::transactionLevel() > 0) return $build();
        $key = self::prefix().':'.self::version().':'.$scope.':'.$part;
        if ($refresh) Cache::forget($key);
        if (($cached = Cache::get($key)) !== null) return $cached;
        // Prevent simultaneous filters from rebuilding the same history twice.
        return Cache::lock($key.':build', 120)->block(90, fn () => Cache::remember($key, now()->addMinutes(15), $build));
    }

    public static function written(QueryExecuted $event): void
    {
        // Covers Eloquent, bulk query-builder updates and reconciliation SQL.
        if (!preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+[`"]?([a-z_]+)/i', $event->sql, $m)
            || !in_array(strtolower($m[1]), self::TABLES, true)) return;
        // Session bookkeeping does not change a report's customer or scope data.
        if (strtolower($m[1]) === 'users' && preg_match('/^\s*update/i', $event->sql)
            && !preg_match('/[`"]?(?:name|email|status|owner_user_id|assigned_country_ids|assigned_location_ids|assigned_listing_ids|assigned_program_ids|custom_permissions)[`"]?\s*=/i', $event->sql)) return;
        $invalidate = fn () => Cache::forever(self::prefix().':version', (string) Str::uuid());
        if ($event->connection->transactionLevel() > 0) $event->connection->afterCommit($invalidate);
        else $invalidate();
    }
}
