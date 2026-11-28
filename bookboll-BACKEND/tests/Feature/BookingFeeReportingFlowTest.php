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
    public array $sessions = [];
    public array $unpaid = [];
    public function request($method, $url, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->calls[] = [$method, $path];
        $body = match (true) {
            $path === '/v1/checkout/sessions' && $method === 'post' => $this->newCheckout($params),
            str_starts_with($path, '/v1/checkout/sessions/cs_test_fee_flow') => $this->paidSession(basename($path))->toArray(),
            $path === '/v1/customers/cus_fee_flow' => ['id'=>'cus_fee_flow','object'=>'customer'],
            $path === '/v1/accounts/acct_fee_flow' => ['id'=>'acct_fee_flow','object'=>'account','country'=>'US','charges_enabled'=>true,'payouts_enabled'=>true,'capabilities'=>['transfers'=>'active']],
            str_starts_with($path, '/v1/payment_intents/pi_fee_flow') => $this->intent(basename($path)),
            default => throw new RuntimeException("Unexpected Stripe request: $method $path"),
        };
        return [json_encode($body), 200, []];
    }
    private function newCheckout(array $params): array
    {
        $this->checkout = $params;
        $id = 'cs_test_fee_flow'.(count($this->sessions) ? '_'.(count($this->sessions)+1) : '');
        $this->sessions[$id] = $params;
        return ['id'=>$id,'object'=>'checkout.session','url'=>'https://checkout.stripe.com/'.$id];
    }
    private function intent(string $id): array
    {
        $checkout = $this->sessions[str_replace('pi_', 'cs_test_', $id)];
        return array_merge([
            'id'=>$id,'object'=>'payment_intent','status'=>'succeeded',
            'amount_received'=>$checkout['line_items'][0]['price_data']['unit_amount'],
            'currency'=>$checkout['line_items'][0]['price_data']['currency'],
            'transfer_data'=>null,
            'latest_charge'=>['id'=>str_replace('pi_', 'ch_', $id),'object'=>'charge','receipt_url'=>'https://pay.stripe.com/receipts/test_fee_flow'],
        ], $checkout['payment_intent_data'] ?? []);
    }
    public function paidSession(?string $id = null): \Stripe\Checkout\Session
    {
        $id ??= array_key_last($this->sessions);
        $checkout = $this->sessions[$id];
        $paid = !in_array($id, $this->unpaid, true);
        return \Stripe\Checkout\Session::constructFrom([
            'id'=>$id,'object'=>'checkout.session','payment_status'=>$paid?'paid':'unpaid',
            'payment_intent'=>$paid?str_replace('cs_test_', 'pi_', $id):null,'metadata'=>$checkout['metadata'],
            'status'=>$paid?'complete':'expired',
            'amount_total'=>$checkout['line_items'][0]['price_data']['unit_amount'],
            'currency'=>$checkout['line_items'][0]['price_data']['currency'],
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
        $conversion = $response->json('data.checkout_conversion');
        expect($conversion['value'])->toEqual($deposit?$fee:200)
            ->and($conversion['currency'])->toBe('QAR')
            ->and($conversion['surface'])->toBe($surface)
            ->and($conversion['num_items'])->toBe(2)
            ->and($conversion['content_ids'])->toBe([(string)$this->listing->id]);
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
        // The return endpoint exposes one verified conversion for the entire charge.
        $return = $this->actingAs($this->customer)->getJson('/api/customer/payments/checkout-session/cs_test_fee_flow')->assertOk()->json('data');
        expect($return['conversion']['value'])->toEqual($deposit?$fee:200)
            ->and($return['conversion']['currency'])->toBe('QAR')
            ->and($return['conversion']['num_items'])->toBe(2)
            ->and($return['conversion']['event_id'])->toBe('bb_purchase_'.hash('sha256','pi_fee_flow'));
        $this->getJson('/api/customer/payments/checkout-session/cs_test_fee_flow')->assertJsonPath('data.conversion', $return['conversion']);
        $redirect = $this->get('/payment/success/cs_test_fee_flow')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $returnQuery);
        expect($returnQuery['checkout_session'])->toBe('cs_test_fee_flow')
            ->and($returnQuery['booking_ids'])->toBe($bookings->pluck('id')->implode(','));
        if ($surface === 'site' && $operator === 'provider') expect($returnQuery['b'])->toBe('qa-fee-flow');
        $other = User::factory()->create(['status'=>'active']);
        $other->assignRole('customer');
        $this->actingAs($other)->getJson('/api/customer/payments/checkout-session/cs_test_fee_flow')->assertNotFound();
        $this->actingAs($this->customer);
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

it('keeps sequential different-listing checkouts distinct even when payment returns arrive out of order', function ($plan, $surface, $method) {
    config(['payments.minimum_charge.PKR'=>200]);
    if ($plan === 'premium') {
        $this->business->update(['plan'=>'premium']);
        BusinessSubscription::create(['business_id'=>$this->business->id,'plan'=>'premium','status'=>'active','billing'=>'yearly','amount'=>588,'currency'=>'USD','current_period_end'=>now()->addYear()]);
    }
    BusinessPaymentConnection::create(['business_id'=>$this->business->id,'stripe_account_id'=>'acct_fee_flow',
        'connected_by'=>$this->owner->id,'livemode'=>false,'status'=>'active','charges_enabled'=>true,'payouts_enabled'=>true,'transfers_enabled'=>true]);
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>$this->country->id],['values'=>['facility'=>18.5,'site'=>8.25]]);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['facility'=>12.5,'site'=>6.5]]);
    app(MarketSettings::class)->clear();
    $deposit = $method === 'deposit_remaining_on_arrival';
    // Representative Railway listing configurations, copied into disposable fixtures.
    // Prices must come from the selected slot, not the cheaper listing-card price.
    $cases = [
        ['name'=>'Badminton', 'base'=>45, 'minutes'=>60, 'prices'=>[50,60], 'rate'=>20],
        ['name'=>'Indoor padel', 'base'=>100, 'minutes'=>120, 'prices'=>[125], 'rate'=>20],
        ['name'=>'Cricket nets', 'base'=>75, 'minutes'=>120, 'prices'=>[100], 'rate'=>null, 'city'=>false],
        ['name'=>'Shared safari', 'base'=>99, 'minutes'=>180, 'prices'=>[99], 'quantity'=>3, 'rate'=>15],
        ['name'=>'Lahore court', 'base'=>100, 'minutes'=>60, 'prices'=>[2299,2299], 'currency'=>'PKR', 'rate'=>15],
        ['name'=>'Low deposit Lahore court', 'base'=>100, 'minutes'=>60, 'prices'=>[555], 'currency'=>'PKR', 'rate'=>15],
        ['name'=>'Fixed deposit court', 'base'=>250, 'minutes'=>60, 'prices'=>[250], 'fixed'=>25, 'rate'=>15],
        ['name'=>'Court with equipment', 'base'=>125, 'minutes'=>60, 'prices'=>[125], 'addon'=>9.5, 'rate'=>15],
        ['name'=>'Provider promo court', 'base'=>125, 'minutes'=>60, 'prices'=>[125], 'promo'=>'provider', 'rate'=>15],
        ['name'=>'Bookboll promo court', 'base'=>125, 'minutes'=>60, 'prices'=>[125], 'promo'=>'bookboll', 'rate'=>15],
    ];
    $runs = [];
    $firstPayload = null;
    $this->listing->resources()->first()->update(['name'=>'Fee flow initial court']);
    $this->actingAs($this->customer);
    foreach ($cases as $case) {
        ['venue'=>$venue,'resource'=>$resource,'listings'=>$listings,'date'=>$date] = courtPool(12, ['court'=>['max'=>8]]);
        $resource->update(['name'=>'Fee flow court '.$venue->id]);
        $currency = $case['currency'] ?? 'QAR';
        $hasCity = $case['city'] ?? true;
        $venue->forceFill(['owner_user_id'=>$this->owner->id,'business_id'=>$this->business->id,'operator_type'=>'provider',
            'country_id'=>$this->country->id,'city_id'=>$hasCity?$this->city->id:null,'currency_override'=>$currency,'min_advance_booking'=>0])->save();
        $listing = $listings['court'];
        $listing->update(['name'=>$case['name'],'base_price'=>$case['base'],'slot_duration'=>$case['minutes'],
            'listing_type'=>isset($case['quantity'])?'per_person':'court','commission_rate'=>$case['rate'],
            'min_advance_minutes'=>0,'show_on_customer'=>true,'show_on_admin'=>true,
            'payment_options'=>['deposit_enabled'=>true,'deposit'=>['enabled'=>true,'amount'=>$case['fixed']??0],
                'online_payment_enabled'=>true,'site_deposit_enabled'=>true,'site_online_enabled'=>true]]);
        $payload = ['venue_id'=>$venue->id,'listing_id'=>$listing->id,'surface'=>$surface,'payment_method'=>$method,'slots'=>[],'add_ons'=>[]];
        $time = \Carbon\Carbon::parse($date.' 20:00');
        foreach ($case['prices'] as $price) {
            $start = $time->format('H:i');
            $end = $time->addMinutes($case['minutes'])->format('H:i');
            $listing->pricingGroups()->create(['name'=>'QA selected-slot price','days'=>[strtolower(\Carbon\Carbon::parse($date)->format('l'))],
                'start_time'=>$start,'end_time'=>$end,'price_per_hour'=>$price,'currency'=>$currency]);
            $payload['slots'][] = ['date'=>$date,'start_time'=>$start,'end_time'=>$end,'quantity'=>$case['quantity']??1];
        }
        if (isset($case['addon'])) {
            $addon = \App\Models\AddOn::create(['name'=>'QA equipment','price'=>$case['addon'],'status'=>'active','currency'=>$currency]);
            $listing->addOns()->attach($addon->id);
            $payload['add_ons'] = [$addon->id];
        }
        if (isset($case['promo'])) {
            $promo = \App\Models\Promocode::create(['description'=>'QA conversion discount','code'=>'QACONV'.$listing->id,
                'status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>$case['promo'],
                'owner_user_id'=>$case['promo']==='provider'?$this->owner->id:null,'payment_methods'=>'all']);
            $payload['promocode'] = $promo->code;
        }
        $gross = array_sum($case['prices']) * ($case['quantity']??1) + ($case['addon']??0);
        $rate = $surface === 'site' ? ($hasCity?6.5:8.25) : ($case['rate']??18.5);
        // Each booking's receipt is rounded first, then the checkout sums them.
        $fee = 0;
        foreach ($case['prices'] as $index=>$price) {
            $slotGross = $price * ($case['quantity']??1) + ($index===0?($case['addon']??0):0);
            $fee += $deposit && $surface === 'marketplace' && isset($case['fixed']) ? $case['fixed'] : round($slotGross*$rate/100,2);
        }
        $fee = round($fee,2);
        $discount = isset($case['promo']) ? round($gross*0.1,2) : 0;
        if (($case['promo']??'') === 'bookboll') $discount = min($discount,$fee);
        $total = round($gross-$discount,2);
        $fee = ($case['promo']??'') === 'bookboll' ? round($fee-$discount,2)
            : (isset($case['promo']) ? round($total*$rate/100,2) : $fee);
        $amount = $deposit?$fee:$total;
        $before = count($this->stripeTransport->sessions);
        $response = $this->postJson('/api/customer/payments/create-checkout-session',$payload);
        if ($currency === 'PKR' && $amount < 200) {
            $response->assertUnprocessable()->assertJsonPath('success',false);
            expect($response->json('data.checkout_conversion'))->toBeNull()
                ->and(count($this->stripeTransport->sessions))->toBe($before);
            continue;
        }
        $result = $response->assertOk()->assertJsonPath('success',true)->json('data');
        if ($amount == 0) {
            expect($result['free_booking'])->toBeTrue()->and($result['checkout_conversion']??null)->toBeNull()
                ->and(count($this->stripeTransport->sessions))->toBe($before);
            continue;
        }
        expect($result['checkout_conversion']['value'])->toEqual($amount)
            ->and($result['checkout_conversion']['currency'])->toBe($currency)
            ->and($result['checkout_conversion']['content_ids'])->toBe([(string)$listing->id])
            ->and($result['checkout_conversion']['num_items'])->toBe(count($case['prices']));
        $runs[] = ['id'=>$result['session_id'],'conversion'=>$result['checkout_conversion']];
        $firstPayload ??= $payload;
    }
    // Buying the SAME listing again is a new payment, not a duplicate event.
    $repeat = $this->postJson('/api/customer/payments/create-checkout-session',$firstPayload)->assertOk()->json('data');
    expect($repeat['checkout_conversion']['event_id'])->not->toBe($runs[0]['conversion']['event_id']);
    $runs[] = ['id'=>$repeat['session_id'],'conversion'=>$repeat['checkout_conversion']];
    $expired = $this->postJson('/api/customer/payments/create-checkout-session',$firstPayload)->assertOk()->json('data.session_id');
    $this->stripeTransport->unpaid[] = $expired;
    $this->getJson('/api/customer/payments/checkout-session/'.$expired)->assertOk()->assertJsonPath('data.conversion',null);

    $events = [];
    foreach (array_reverse($runs) as $run) {
        $data = $this->getJson('/api/customer/payments/checkout-session/'.$run['id'])->assertOk()->json('data');
        $event = $data['conversion'];
        expect($event)->not->toBeNull();
        foreach (['value','currency','content_ids','num_items','surface','business_id'] as $field) expect($event[$field])->toBe($run['conversion'][$field]);
        expect($event['content_ids'])->toBe(Booking::whereIn('id',$data['booking_ids'])->pluck('listing_id')->unique()->map(fn($id)=>(string)$id)->values()->all());
        expect((float)Payment::whereIn('booking_id',$data['booking_ids'])->where('status','paid')->where('method','online')->sum('amount'))->toEqual($event['value']);
        $this->getJson('/api/customer/payments/checkout-session/'.$run['id'])->assertOk()->assertJsonPath('data.conversion',$event);
        $events[] = $event['event_id'];
    }
    expect(array_unique($events))->toHaveCount(count($runs));
    // A rejected booking cannot accidentally reuse the previous successful payload.
    $firstPayload['slots'][0]['quantity'] = 999;
    $count = count($this->stripeTransport->sessions);
    $failed = $this->postJson('/api/customer/payments/create-checkout-session',$firstPayload);
    expect($failed->json('success'))->toBeFalse()->and($failed->json('data.checkout_conversion'))->toBeNull()
        ->and(count($this->stripeTransport->sessions))->toBe($count);
})->with(['free','premium'])->with(['marketplace','site'])->with(['deposit_remaining_on_arrival','online_payment']);
