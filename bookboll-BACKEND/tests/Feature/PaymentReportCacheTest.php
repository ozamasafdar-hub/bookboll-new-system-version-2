<?php
use App\Support\PaymentReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses normalized reports but invalidates committed writes and never caches a transaction', function () {
    // Leave the test wrapper to exercise real commit callbacks, then restore it.
    DB::commit();
    DB::connection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager);
    try {
        $builds = 0;
        $build = function () use (&$builds) { return ['build'=>++$builds]; };
        expect(PaymentReportCache::remember('platform','rows',$build))->toBe(['build'=>1]);
        expect(PaymentReportCache::remember('platform','rows',$build))->toBe(['build'=>1]);
        expect(PaymentReportCache::remember('provider','rows',$build))->toBe(['build'=>2]);
        $version = PaymentReportCache::version();
        DB::beginTransaction();
        DB::table('payments')->where('id',-1)->update(['status'=>'paid']);
        expect(PaymentReportCache::version())->toBe($version);
        expect(PaymentReportCache::remember('platform','rows',$build))->toBe(['build'=>3]);
        DB::rollBack();
        expect(PaymentReportCache::version())->toBe($version);
        expect(PaymentReportCache::remember('platform','rows',$build))->toBe(['build'=>1]);
        DB::transaction(fn () => DB::table('payments')->where('id',-1)->update(['status'=>'paid']));
        expect(PaymentReportCache::version())->not->toBe($version);
        expect(PaymentReportCache::remember('platform','rows',$build))->toBe(['build'=>4]);
        expect(PaymentReportCache::remember('platform','rows',$build,true))->toBe(['build'=>5]);
        $version = PaymentReportCache::version();
        DB::table('users')->where('id',-1)->update(['last_login_at'=>now()]);
        expect(PaymentReportCache::version())->toBe($version);
        DB::table('users')->where('id',-1)->update(['name'=>'Renamed']);
        expect(PaymentReportCache::version())->not->toBe($version);
    } finally {
        while (DB::transactionLevel()) DB::rollBack();
        DB::beginTransaction();
    }
});
