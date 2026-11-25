<?php

use App\Models\MarketSetting;
use App\Support\MarketSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function unifiedFeeMigration() { return require database_path('migrations/2026_11_25_000001_unify_website_booking_fees.php'); }

beforeEach(function () {
    DB::table('settings')->where('key', 'unified_website_booking_fees_20261125')->delete();
});

it('migrates two global fees once, preserves unambiguous local overrides and archives retired rates', function () {
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0], ['values'=>['facility'=>22,'site_free'=>15,'site_premium'=>10,'event'=>7]]);
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>123], ['values'=>['site_free'=>0,'site_premium'=>null,'facility'=>18]]);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>456], ['values'=>['site_free'=>7.25,'site_premium'=>7.25,'comeback_enabled'=>false]]);
    $user = \App\Models\User::factory()->create();
    $venue = \App\Models\Venue::create(['name'=>'Legacy venue','status'=>'active']);
    $booking = \App\Models\Booking::create(['user_id'=>$user->id,'venue_id'=>$venue->id,'date'=>'2026-11-01',
        'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,'status'=>'confirmed',
        'commission_channel'=>'site','commission_effective_pct'=>15,'commission_fee_minor'=>1500,
        'merchant_share_minor'=>8500,'commission_currency'=>'QAR','commission_plan_eligible'=>false]);
    $payment = \App\Models\Payment::create(['user_id'=>$user->id,'booking_id'=>$booking->id,'payment_id'=>'QA-HISTORIC-15',
        'type'=>'deposit','date'=>'2026-11-01','amount'=>15,'currency'=>'QAR','method'=>'online','status'=>'paid',
        'financial_context'=>['fee_minor'=>1500,'merchant_share_minor'=>8500]]);
    $beforeBooking = $booking->fresh()->getRawOriginal();
    $beforePayment = $payment->fresh()->getRawOriginal();
    unifiedFeeMigration()->up();
    $values = fn($scope,$id) => MarketSetting::where('scope',$scope)->where('scope_id',$id)->first()->values;
    expect($values('global',0))->toBe(['facility'=>15,'event'=>7,'site'=>10])
        ->and($values('country',123))->toBe(['facility'=>18,'site'=>0])
        ->and($values('city',456))->toBe(['comeback_enabled'=>false,'site'=>7.25]);
    $audit = json_decode(DB::table('settings')->where('key','unified_website_booking_fees_20261125')->value('value'),true);
    expect($audit['previous_settings'])->not->toBeEmpty();
    expect($booking->fresh()->getRawOriginal())->toBe($beforeBooking)->and($payment->fresh()->getRawOriginal())->toBe($beforePayment);
    MarketSetting::where('scope','global')->where('scope_id',0)->update(['values'=>['facility'=>16,'site'=>4]]);
    unifiedFeeMigration()->up();
    unifiedFeeMigration()->down();
    expect($values('global',0))->toBe(['facility'=>16,'site'=>4]);
});

it('refuses to guess between conflicting local rates and rolls back without an audit marker', function () {
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0], ['values'=>['facility'=>22,'site_free'=>15,'site_premium'=>10]]);
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>123], ['values'=>['site_free'=>12,'site_premium'=>6]]);
    $before = DB::table('market_settings')->orderBy('id')->get()->toJson();
    expect(fn()=>unifiedFeeMigration()->up())->toThrow(RuntimeException::class, 'Conflicting legacy website fees at country:123');
    expect(DB::table('market_settings')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('settings')->where('key','unified_website_booking_fees_20261125')->exists())->toBeFalse();
});

it('honours a reviewed local site rate even when the retired fields disagree', function () {
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>123], ['values'=>['site_free'=>12,'site_premium'=>6,'site'=>0]]);
    unifiedFeeMigration()->up();
    expect(MarketSetting::where('scope','country')->where('scope_id',123)->first()->values)->toBe(['site'=>0]);
});

it('does not expose retired fee fields even if a legacy row is later imported', function () {
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0], ['values'=>['site_free'=>75,'site_premium'=>1,'site'=>8]]);
    app(MarketSettings::class)->clear();
    $response = $this->getJson('/api/business/booking-fees')->assertOk();
    expect($response->json('data.rates'))->toBe(['facility'=>15,'site'=>8]);
});
