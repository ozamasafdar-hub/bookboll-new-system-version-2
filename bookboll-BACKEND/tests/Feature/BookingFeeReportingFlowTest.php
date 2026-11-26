<?php

use App\Models\{Booking, Business, BusinessMembership, BusinessPaymentConnection, BusinessSubscription, MarketSetting, Payment, ProviderWallet, StripeSession, User};
use App\Services\StripeService;
use App\Support\{Capabilities, MarketSettings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Storage};
use Nnjeim\World\Models\{City, Country, State};
use Spatie\Permission\Models\{Permission, Role};
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// Only the processor transport is simulated. Booking validation, fee resolution,
// persisted payments, Connect allocation, settlement, collection and reporting
// use the real application paths and the disposable MySQL database.
class FeeFlowStripeTransport implements \Stripe\HttpClient\ClientInterface
{
    public array $checkout = [];
    public array $calls = [];
    public function request($method, $url, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->calls[] = [$method, $path];
        $body = match (true) {
            $path === '/v1/checkout/sessions' && $method === 'post' => $this->newCheckout($params),
            $path === '/v1/customers/cus_fee_flow' => ['id'=>'cus_fee_flow','object'=>'customer'],
            $path === '/v1/accounts/acct_fee_flow' => ['id'=>'acct_fee_flow','object'=>'account','country'=>'US','charges_enabled'=>true,'payouts_enabled'=>true,'capabilities'=>['transfers'=>'active']],
            $path === '/v1/payment_intents/pi_fee_flow' => array_merge([
                'id'=>'pi_fee_flow','object'=>'payment_intent','status'=>'succeeded',
                'amount_received'=>$this->checkout['line_items'][0]['price_data']['unit_amount'],
                'currency'=>$this->checkout['line_items'][0]['price_data']['currency'],
                'transfer_data'=>null,
                'latest_charge'=>['id'=>'ch_fee_flow','object'=>'charge','receipt_url'=>'https://pay.stripe.com/receipts/test_fee_flow'],
            ], $this->checkout['payment_intent_data'] ?? []),
            default => throw new RuntimeException("Unexpected Stripe request: $method $path"),
        };
        return [json_encode($body), 200, []];
    }
    private function newCheckout(array $params): array
    {
        $this->checkout = $params;
        return ['id'=>'cs_test_fee_flow','object'=>'checkout.session','url'=>'https://checkout.stripe.com/test_fee_flow'];
    }
    public function paidSession(): \Stripe\Checkout\Session
    {
        return \Stripe\Checkout\Session::constructFrom([
            'id'=>'cs_test_fee_flow','object'=>'checkout.session','payment_status'=>'paid',
            'payment_intent'=>'pi_fee_flow','metadata'=>$this->checkout['metadata'],
        ]);
    }
}

beforeEach(function () {
    config(['preview.enabled'=>false,'preview.workspace'=>false,'services.stripe.secret'=>'sk_test_fee_flow','cashier.secret'=>'sk_test_fee_flow']);
    Http::preventStrayRequests();
    Notification::fake();
    Mail::fake();
    Storage::fake('public');
    // Invoice layout is not under test; avoid loading remote PDF assets.
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andReturnSelf();
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('output')->andReturn('%PDF-test');
    foreach (['super_admin','provider_admin','customer'] as $name) {
        $role = Role::findOrCreate($name, 'web');
        $permissions = Capabilities::roleMap()[$name] ?? [];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $role->syncPermissions($permissions);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->platform = User::factory()->create(['status'=>'active']);
    $this->platform->assignRole('super_admin');
    $this->owner = User::factory()->create(['status'=>'active']);
    $this->owner->assignRole('provider_admin');
    $this->customer = User::factory()->create(['status'=>'active','stripe_id'=>'cus_fee_flow']);
    $this->customer->assignRole('customer');
    $this->country = Country::create(['name'=>'QA fee country','iso2'=>'ZQ','iso3'=>'ZQA','phone_code'=>'0','region'=>'Asia','subregion'=>'Asia','status'=>1]);
    $state = State::create(['name'=>'QA state','country_id'=>$this->country->id,'country_code'=>'ZQA']);
    $this->city = City::create(['name'=>'QA fee city','country_id'=>$this->country->id,'country_code'=>'ZQA','state_id'=>$state->id,'status'=>1]);
    $this->business = Business::create(['name'=>'QA fee business','username'=>'qa-fee-flow','owner_id'=>$this->owner->id,'country_id'=>$this->country->id,'plan'=>'free','status'=>'active']);
    BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$this->owner->id,'role'=>'owner','scope'=>'all','status'=>'active']);
    ['venue'=>$this->venue,'listings'=>$listings,'date'=>$this->date] = courtPool(4, ['court'=>['max'=>2]]);
    $this->venue->forceFill(['owner_user_id'=>$this->owner->id,'business_id'=>$this->business->id,'operator_type'=>'provider','country_id'=>$this->country->id,'city_id'=>$this->city->id,'currency_override'=>'QAR','min_advance_booking'=>0])->save();
    $this->listing = $listings['court'];
    $this->listing->update(['base_price'=>100,'commission_rate'=>null,'min_advance_minutes'=>0,'show_on_customer'=>true,'show_on_admin'=>true,'payment_options'=>[
        'deposit_enabled'=>true,'deposit'=>['enabled'=>true,'percentage'=>99],
        'online_payment_enabled'=>true,'site_deposit_enabled'=>true,'site_deposit_percentage'=>99,'site_online_enabled'=>true,
    ]]);
    $this->stripeTransport = new FeeFlowStripeTransport();
    \Stripe\ApiRequestor::setHttpClient($this->stripeTransport);
});

afterEach(function () {
    \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient());
});

function feeFlowReport(User $actor, array $expected, int $entries): array
{
    $test = test()->actingAs($actor);
    $ledger = $test->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe')->assertOk()->json('data');
    $analytics = $test->getJson('/api/admin/payments/analytics')->assertOk()->json('data');
    expect($analytics['summary'])->toBe($ledger['summary']);
    expect($ledger['payments']['total'])->toBe($entries);
    $qar = collect($ledger['summary'])->firstWhere('currency', 'QAR');
    foreach ($expected as $key=>$amount) expect($qar[$key] ?? null)->toEqual($amount, $key);
    foreach (['timeline','channels','methods','venues'] as $dimension) {
        foreach ($expected as $key=>$amount) {
            $sum = collect($analytics['analytics'][$dimension])->sum(fn($bucket)=>collect($bucket['summary'])->firstWhere('currency','QAR')[$key] ?? 0);
            expect(round($sum, 2))->toEqual($amount, "$dimension / $key");
        }
    }
    return $ledger;
}

it('keeps real checkout, Stripe allocation, receipts and analytics consistent', function ($plan, $surface, $level, $method, $operator) {
    if ($plan === 'premium') {
        $this->business->update(['plan'=>'premium']);
        BusinessSubscription::create(['business_id'=>$this->business->id,'plan'=>'premium','status'=>'active','billing'=>'yearly','amount'=>588,'currency'=>'USD','current_period_end'=>now()->addYear()]);
    }
    if ($operator === 'bookboll') $this->venue->forceFill(['operator_type'=>'bookboll','business_id'=>null,'owner_user_id'=>null])->save();
    if ($level !== 'global') MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>$this->country->id],['values'=>['facility'=>18.5,'site'=>8.25]]);
    if (in_array($level, ['city','zero'])) MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>$level==='zero'?['facility'=>0,'site'=>0]:['facility'=>12.75,'site'=>6.5]]);
    app(MarketSettings::class)->clear();
    $rate = ['global'=>['marketplace'=>15,'site'=>10],'country'=>['marketplace'=>18.5,'site'=>8.25],'city'=>['marketplace'=>12.75,'site'=>6.5],'zero'=>['marketplace'=>0,'site'=>0]][$level][$surface];
    $fee = $rate * 2; // Two QAR 100 slots, not one fee counted twice.
    $share = 200 - $fee;
    $deposit = $method === 'deposit_remaining_on_arrival';
    if (!$deposit && $operator === 'provider') BusinessPaymentConnection::create([
        'business_id'=>$this->business->id,'stripe_account_id'=>'acct_fee_flow','connected_by'=>$this->owner->id,
        'livemode'=>false,'status'=>'active','charges_enabled'=>true,'payouts_enabled'=>true,'transfers_enabled'=>true,
    ]);

    $this->actingAs($this->customer);
    $response = $this->postJson('/api/customer/payments/create-checkout-session', [
        'venue_id'=>$this->venue->id,'listing_id'=>$this->listing->id,'surface'=>$surface,'payment_method'=>$method,'add_ons'=>[],
        'slots'=>[
            ['date'=>$this->date,'start_time'=>'20:00','end_time'=>'21:00','quantity'=>1],
            ['date'=>$this->date,'start_time'=>'21:00','end_time'=>'22:00','quantity'=>1],
        ],
        // Client-provided fees, geography and plan cannot override server policy.
        'commission_rate'=>99,'platform_fee'=>1,'country_id'=>99999,'plan'=>'free',
    ])->assertOk()->assertJsonPath('success', true);
    $bookings = Booking::orderBy('id')->get();
    expect($bookings)->toHaveCount(2);
    foreach ($bookings as $b) {
        expect((float)$b->total_price)->toBe(100.0)
            ->and((float)$b->commission_effective_pct)->toBe((float)$rate)
            ->and((int)$b->commission_fee_minor)->toBe((int)round($rate * 100))
            ->and((int)$b->merchant_share_minor)->toBe(10000 - (int)round($rate * 100))
            ->and($b->financial_context['channel'])->toBe($surface)
            ->and($b->financial_context['operator'])->toBe($operator);
    }
    expect((float)Payment::sum('amount'))->toBe(200.0);
    if (!$deposit) foreach ($bookings as $b) {
        $allocations = $b->payments()->where('type','full')->firstOrFail()->ownedAllocations;
        expect($allocations->where('bucket','platform')->sum('amount_minor'))->toBe((int)round($rate*100))
            ->and($allocations->where('bucket','venue')->sum('amount_minor'))->toBe(10000-(int)round($rate*100));
    }
    feeFlowReport($this->platform, [
        'total_sales'=>200,'platform_fees'=>0,'bookboll_received'=>0,
        'provider_pending'=>$operator==='provider'?$share:0,
        'owned_pending'=>$operator==='bookboll'?200:0,
    ], $deposit?2:1);

    // Changing settings and plan between checkout and settlement must not
    // rewrite the amount already quoted, or any later analytics read.
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['facility'=>44,'site'=>33]]);
    app(MarketSettings::class)->clear();
    $this->business->update(['plan'=>$plan==='free'?'premium':'free']);
    $this->business->subscriptions()->update(['status'=>'canceled']);
    if ($deposit && $rate == 0) {
        expect($this->stripeTransport->calls)->toBe([]);
        $response->assertJsonPath('data.remaining_on_arrival', true);
    } else {
        $checkout = $this->stripeTransport->checkout;
        expect($checkout['line_items'][0]['price_data']['unit_amount'])->toBe((int)round(($deposit?$fee:200)*100));
        if (!$deposit && $operator === 'provider') {
            expect($checkout['payment_intent_data']['application_fee_amount'])->toBe((int)round($fee*100))
                ->and($checkout['payment_intent_data']['transfer_data']['destination'])->toBe('acct_fee_flow');
        } else expect($checkout)->not->toHaveKey('payment_intent_data');
        $result = app(StripeService::class)->fulfillPaidCheckoutSession('cs_test_fee_flow', $this->stripeTransport->paidSession());
        expect($result['processed'])->toBeTrue($result['error'] ?? '')
            ->and($result['unsettled'])->toBe([]);
        $again = app(StripeService::class)->fulfillPaidCheckoutSession('cs_test_fee_flow', $this->stripeTransport->paidSession());
        expect($again['already_processed'])->toBeTrue();
        expect(StripeSession::sole()->processed)->toBeTrue();
        foreach (Payment::where('status','paid')->get() as $p) expect($p->charge_id)->toBe('ch_fee_flow');
    }
    expect(ProviderWallet::count())->toBe(0); // Direct split must not also credit a wallet.
    foreach ($bookings as $b) {
        expect($b->fresh()->status)->toBe('confirmed')
            ->and($b->fresh()->payment_status)->toBe($deposit?'deposit_paid':'fully_paid')
            ->and($b->fresh()->due_amount)->toEqual($deposit?$share/2:0);
    }

    $owned = $operator === 'bookboll';
    $expected = [
        'total_sales'=>200,'platform_fees'=>$fee,'bookboll_received'=>$owned&&!$deposit?200:$fee,
        'provider_total_sales'=>$owned?0:200,'provider_received'=>!$owned&&!$deposit?$share:0,
        'provider_pending'=>!$owned&&$deposit?$share:0,
        'owned_venue_received'=>$owned&&!$deposit?$share:0,
        'owned_pending'=>$owned&&$deposit?$share:0,
        'owned_venue_pending'=>$owned&&$deposit?$share:0,
        'owned_platform_fees'=>$owned?$fee:0,
        'external_booking_fees'=>$owned?0:$fee,
        'marketplace_fees'=>$surface==='marketplace'?$fee:0,
        'site_fees'=>$surface==='site'?$fee:0,
    ];
    // The saved checkout reference groups zero-deposit slots without inventing
    // a processor charge or any received money.
    $entries = $deposit ? 2 : 1;
    $report = feeFlowReport($this->platform, $expected, $entries);
    expect($report['review_count'])->toBe(0)->and($report['fee_split_count'])->toBe(0);
    if (!$owned) feeFlowReport($this->owner, $expected, $entries);
    else $this->actingAs($this->owner)->getJson('/api/admin/payments/analytics')->assertOk()->assertJsonPath('data.summary', []);

    if ($deposit) {
        $this->actingAs($owned ? $this->platform : $this->owner);
        $balances = Payment::where('type','remaining')->orderBy('id')->get();
        foreach ($balances as $balance) {
            if ($balance->fresh()->status === 'paid') continue;
            $base = '/api/admin/payments/'.$balance->id;
            $review = $this->getJson($base.'/collection-preview')->assertOk()->json('data');
            $this->postJson($base.'/collect-checkout',['fingerprint'=>$review['fingerprint'],'method'=>'cash'])->assertOk();
        }
        $expected['bookboll_received'] = $owned ? 200 : $fee;
        $expected['provider_received'] = $owned ? 0 : $share;
        $expected['provider_pending'] = 0;
        $expected['owned_venue_received'] = $owned ? $share : 0;
        $expected['owned_pending'] = $expected['owned_venue_pending'] = 0;
        feeFlowReport($this->platform, $expected, $entries);
        if (!$owned) feeFlowReport($this->owner, $expected, $entries);
        expect((float)Payment::where('status','paid')->sum('amount'))->toBe(200.0);
    }
})->with(['free','premium'])->with(['marketplace','site'])->with(['global','country','city','zero'])
  ->with(['deposit_remaining_on_arrival','online_payment'])->with(['provider','bookboll']);
