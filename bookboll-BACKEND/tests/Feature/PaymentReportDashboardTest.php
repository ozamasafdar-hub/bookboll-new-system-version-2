<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\LoyaltyTier;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Support\Capabilities;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $tier = LoyaltyTier::where('slug','bronze')->value('id');
    foreach (['super_admin','provider_admin'] as $name) {
        $role = Role::findOrCreate($name,'web');
        $permissions = Capabilities::roleMap()[$name];
        foreach ($permissions as $permission) Permission::findOrCreate($permission,'web');
        $role->syncPermissions($permissions);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->platform = User::factory()->create(['status'=>User::ACTIVE,'loyalty_tier_id'=>$tier]);
    $this->platform->assignRole('super_admin');
    $this->provider = User::factory()->create(['status'=>User::ACTIVE,'loyalty_tier_id'=>$tier]);
    $this->provider->assignRole('provider_admin');
    $this->business = Business::create(['owner_id'=>$this->provider->id,'name'=>'Report fixture business','username'=>'report-fixture','status'=>Business::STATUS_ACTIVE,'plan'=>'free']);
    $this->ownVenue = reportFixtureVenue('Owned fixture',null,null,'bookboll');
    $this->externalVenue = reportFixtureVenue('External fixture',$this->provider->id,$this->business->id,'provider');
});

function reportFixtureVenue($name,$owner,$business,$operator): Venue {
    $v = Venue::create(['name'=>$name,'status'=>Venue::ACTIVE,'maps_url'=>'https://maps.google.com/?q=0,0','owner_user_id'=>$owner,'currency_override'=>'QAR','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $v->forceFill(['operator_type'=>$operator,'business_id'=>$business])->save();
    return $v->fresh();
}
function reportFixtureBooking(Venue $v,User $customer,string $origin='bookboll_web'): Booking {
    return Booking::create(['user_id'=>$customer->id,'venue_id'=>$v->id,'date'=>'2026-11-10','start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,'status'=>'confirmed','payment_status'=>'pending','payment_method'=>'deposit_remaining_on_arrival','origin'=>$origin,'commission_channel'=>'marketplace','commission_effective_pct'=>15]);
}
function reportFixturePayment(Booking $b,string $type,float $amount,string $method,string $status): Payment {
    // Tests report reads without sending payment notifications. Snapshot writer
    // itself is exercised separately below on a pending payment.
    return Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>$type,'amount'=>$amount,'method'=>$method,'status'=>$status,'currency'=>'QAR','date'=>'2026-11-10','payment_id'=>'qa-'.uniqid(),'financial_context'=>$b->financial_context]));
}

function sharedCheckoutFixture($venue, $customer): array {
    $payments = [];
    foreach ([[25,100],[20,80]] as [$deposit,$balance]) {
        $b = reportFixtureBooking($venue,$customer);
        $p = reportFixturePayment($b,'deposit',$deposit,'online','paid');
        $p->forceFill(['charge_id'=>'ch_shared_checkout','payment_intent_id'=>'pi_shared_checkout'])->saveQuietly();
        $payments[] = $p;
        reportFixturePayment($b,'remaining',$balance,'cash','pending');
    }
    return $payments;
}

it('shows one deposit and one remaining payment per checkout before pagination', function () {
    $payments = sharedCheckoutFixture($this->externalVenue,$this->provider);
    $this->actingAs($this->platform);
    $base = '/api/admin/payments/dashboard?report_version=2&group_by=stripe&sort=amount&sort_dir=asc&records_per_page=1';
    $first = $this->getJson($base)->assertOk()->assertJsonPath('data.payments.total',2)
        ->assertJsonPath('data.payments.allocation_total',4)->assertJsonPath('data.summary.0.bookboll_received',45);
    $group = $first->json('data.payments.data.0');
    expect($group['row_kind'])->toBe('online_group')->and($group['amount'])->toEqual(45)
        ->and($group['booking_count'])->toBe(2)->and($group['partial'])->toBeFalse()
        ->and($group['payment_id'])->toStartWith('CHK-')->and($group['payment_id'])->toContain('-ONLINE-');
    $second=$this->getJson($base.'&page=2')->assertOk()->json('data.payments.data.0');
    expect($second['amount'])->toEqual(180)->and($second['row_kind'])->toBe('remaining_group')
        ->and($second['checkout_reference'])->toBe($group['checkout_reference'])
        ->and($second['payment_id'])->toContain('-BAL-');
    $flat = $this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=allocation')->assertOk();
    expect($flat->json('data.payments.total'))->toBe(4)->and($flat->json('data.summary'))->toBe($first->json('data.summary'));
    $this->getJson('/api/admin/payments/'.$payments[0]->id.'/details')->assertOk()
        ->assertJsonPath('data.online_group.amount',45)->assertJsonPath('data.online_group.booking_count',2)
        ->assertJsonPath('data.checkout_payments.0.amount',180);
    $this->getJson('/api/admin/payments/'.$second['anchor']['id'].'/details')->assertOk()
        ->assertJsonPath('data.checkout_payment.amount',180)->assertJsonPath('data.checkout_payments.0.amount',45);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&search='.$group['checkout_reference'])
        ->assertOk()->assertJsonPath('data.payments.total',2);
});

it('collects one checkout balance atomically with one review and an idempotent audit record', function () {
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $balance = Payment::where('type','remaining')->first();
    $base='/api/admin/payments/'.$balance->id;
    $this->actingAs($this->platform)->getJson($base.'/collection-preview')->assertForbidden();
    $this->actingAs($this->provider);
    $review=$this->getJson($base.'/collection-preview')->assertOk()->assertJsonPath('data.amount',180)->json('data');
    $body=['fingerprint'=>$review['fingerprint'],'method'=>'cash'];
    $this->postJson($base.'/collect-checkout',$body)->assertOk()->assertJsonPath('data.already_recorded',false);
    $this->postJson($base.'/collect-checkout',$body)->assertOk()->assertJsonPath('data.already_recorded',true);
    expect(Payment::where('type','remaining')->where('status','paid')->count())->toBe(2);
    $this->assertDatabaseCount('checkout_payment_collections',1);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=cash')
        ->assertOk()->assertJsonPath('data.payments.total',1)->assertJsonPath('data.payments.data.0.amount',180)
        ->assertJsonPath('data.payments.data.0.status','paid')->assertJsonPath('data.summary.0.provider_received',180);
});

it('rolls back every slot if any receipt in a checkout collection fails', function () {
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $balance=Payment::where('type','remaining')->first();
    $base='/api/admin/payments/'.$balance->id;
    $this->actingAs($this->provider);
    $review=$this->getJson($base.'/collection-preview')->assertOk()->json('data');
    $real=app(\App\Services\BookingService::class);
    $calls=0;
    $mock=\Mockery::mock(\App\Services\BookingService::class);
    $mock->shouldReceive('dashboardCollect')->twice()->andReturnUsing(function ($request,$bookingId,$paymentId) use ($real,&$calls) {
        return ++$calls === 2 ? sendError('Collection changed',[],409) : $real->dashboardCollect($request,$bookingId,$paymentId);
    });
    app()->instance(\App\Services\BookingService::class,$mock);
    $this->postJson($base.'/collect-checkout',['fingerprint'=>$review['fingerprint'],'method'=>'cash'])->assertStatus(409);
    expect(Payment::where('type','remaining')->where('status','pending')->count())->toBe(2);
    $this->assertDatabaseCount('checkout_payment_collections',0);
});

it('shows a partly collected checkout and rejects stale or cancelled collection reviews', function () {
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $balances=Payment::where('type','remaining')->orderBy('id')->get();
    $this->actingAs($this->provider);
    $base='/api/admin/payments/'.$balances[1]->id;
    $old=$this->getJson($base.'/collection-preview')->assertOk()->json('data.fingerprint');
    $balances[0]->forceFill(['status'=>'paid'])->saveQuietly();
    $this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=cash')->assertOk()
        ->assertJsonPath('data.payments.data.0.status','partially_paid')->assertJsonPath('data.payments.data.0.paid_amount',100)
        ->assertJsonPath('data.payments.data.0.pending_amount',80);
    $this->postJson($base.'/collect-checkout',['fingerprint'=>$old,'method'=>'cash'])->assertStatus(409);
    $review=$this->getJson($base.'/collection-preview')->assertOk()->assertJsonPath('data.amount',80)->json('data');
    $balances[1]->booking->forceFill(['status'=>'cancelled'])->saveQuietly();
    $this->postJson($base.'/collect-checkout',['fingerprint'=>$review['fingerprint'],'method'=>'cash'])->assertStatus(409);
    expect($balances[1]->fresh()->status)->toBe('pending');
    $this->assertDatabaseCount('checkout_payment_collections',0);
});

it('does not merge remaining balances collected by different operators', function () {
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $other=reportFixtureBooking($this->ownVenue,$this->provider);
    $p=reportFixturePayment($other,'deposit',10,'online','paid');
    $p->forceFill(['charge_id'=>'ch_shared_checkout','payment_intent_id'=>'pi_shared_checkout'])->saveQuietly();
    reportFixturePayment($other,'remaining',40,'cash','pending');
    $rows=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=cash')
        ->assertOk()->assertJsonPath('data.payments.total',2)->json('data.payments.data');
    expect(collect($rows)->pluck('amount')->sort()->values()->all())->toEqual([40,180]);
});

it('stamps new checkout slots with one stable reference before Stripe confirmation', function () {
    $payments=sharedCheckoutFixture($this->externalVenue,$this->provider);
    $bookings=Booking::whereIn('id',array_map(fn ($p)=>$p->booking_id,$payments))->get();
    $reference=\App\Support\CheckoutReference::stamp($bookings);
    expect($bookings->map(fn ($b)=>$b->fresh()->checkout_reference)->unique()->all())->toBe([$reference]);
    foreach ($payments as $p) $p->forceFill(['charge_id'=>null,'payment_intent_id'=>null])->saveQuietly();
    $path='/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online';
    $before=$this->actingAs($this->platform)->getJson($path)->assertOk()->assertJsonPath('data.payments.total',1)->json('data.payments.data.0');
    foreach ($payments as $p) $p->forceFill(['charge_id'=>'ch_later','payment_intent_id'=>'pi_later'])->saveQuietly();
    $after=$this->getJson($path)->assertOk()->json('data.payments.data.0');
    expect($after['payment_id'])->toBe($before['payment_id'])->and($after['checkout_reference'])->toBe($reference);
});

it('labels a filtered part of a shared payment and does not add unmatched fees into its total', function () {
    $payments=sharedCheckoutFixture($this->externalVenue,$this->provider);
    $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&search='.$payments[0]->payment_id)
        ->assertOk()->assertJsonPath('data.payments.total',1)->assertJsonPath('data.payments.allocation_total',1)
        ->assertJsonPath('data.payments.data.0.partial',true)->assertJsonPath('data.payments.data.0.amount',25)
        ->assertJsonPath('data.payments.data.0.booking_count',1)->assertJsonPath('data.payments.data.0.can_request_refund',false)
        ->assertJsonPath('data.summary.0.platform_fees',25);
});

it('keeps shared payments in the same-day order of their original receipt ids', function () {
    $older=reportFixturePayment(reportFixtureBooking($this->externalVenue,$this->provider),'deposit',12,'online','cancelled');
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $newer=reportFixturePayment(reportFixtureBooking($this->externalVenue,$this->provider),'deposit',18,'online','paid');
    $rows=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online&sort=date&sort_dir=desc')
        ->assertOk()->json('data.payments.data');
    expect((int)$rows[0]['id'])->toBe($newer->id)->and($rows[1]['row_kind'])->toBe('online_group')
        ->and((int)$rows[2]['id'])->toBe($older->id);
});

it('groups only authorized provider records and never exposes another business in context', function () {
    sharedCheckoutFixture($this->externalVenue,$this->provider);
    $foreign=reportFixtureBooking($this->ownVenue,$this->platform);
    $p=reportFixturePayment($foreign,'deposit',900,'online','paid');
    $p->forceFill(['charge_id'=>'ch_shared_checkout'])->saveQuietly();
    reportFixturePayment($foreign,'remaining',400,'cash','pending');
    $data=$this->actingAs($this->provider)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online')
        ->assertOk()->assertJsonPath('data.payments.total',1)->json('data.payments.data.0');
    expect($data['amount'])->toEqual(45)->and($data['scoped'])->toBeTrue()->and($data['booking_count'])->toBe(2)
        ->and($data['can_request_refund'])->toBeFalse()->and(json_encode($data))->not->toContain('Owned fixture');
});

it('applies date status and amount filters before grouping and preserves flat summaries', function () {
    $payments=sharedCheckoutFixture($this->externalVenue,$this->provider);
    $payments[1]->forceFill(['date'=>'2026-11-09','status'=>'refunded'])->saveQuietly();
    $this->actingAs($this->platform);
    foreach (['from_date=2026-11-10&to_date=2026-11-10','status=paid','amount_min=25&amount_max=25'] as $filter) {
        $base='/api/admin/payments/dashboard?report_version=2&method=online&'.$filter;
        $group=$this->getJson($base.'&group_by=stripe')->assertOk()
            ->assertJsonPath('data.payments.data.0.amount',25)->assertJsonPath('data.payments.data.0.partial',true)
            ->assertJsonPath('data.payments.allocation_total',1);
        $flat=$this->getJson($base.'&group_by=allocation')->assertOk();
        expect($group->json('data.summary'))->toBe($flat->json('data.summary'));
    }
});

it('groups conserving full-payment allocations without adding their gross receipt again', function () {
    $b=reportFixtureBooking($this->externalVenue,$this->provider);
    $root=reportFixturePayment($b,'full',100,'online','paid');
    $root->forceFill(['charge_id'=>'ch_full_split'])->saveQuietly();
    reportFixturePayment($b,'booking_commission',15,'online','paid');
    reportFixturePayment($b,'booking_earning',85,'online','paid');
    $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe')
        ->assertOk()->assertJsonPath('data.payments.total',1)->assertJsonPath('data.payments.data.0.amount',100)
        ->assertJsonPath('data.payments.data.0.allocation_count',2)->assertJsonPath('data.summary.0.bookboll_received',15);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&scope=bookboll')
        ->assertOk()->assertJsonPath('data.payments.data.0.partial',true)->assertJsonPath('data.payments.data.0.amount',15);
});

it('never merges missing identities different currencies or distinct charge attempts', function () {
    $payments=sharedCheckoutFixture($this->externalVenue,$this->provider);
    $payments[1]->forceFill(['charge_id'=>'ch_other_attempt'])->saveQuietly();
    $path='/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online&refresh=1';
    $this->actingAs($this->platform)->getJson($path)->assertOk()->assertJsonPath('data.payments.total',2);
    $unknown=reportFixturePayment(reportFixtureBooking($this->externalVenue,$this->provider),'deposit',10,'online','paid');
    $unknown->forceFill(['payment_intent_id'=>'pi_shared_checkout'])->saveQuietly();
    $this->getJson($path)->assertOk()->assertJsonPath('data.payments.total',3);
    $unknown->forceFill(['payment_intent_id'=>null])->saveQuietly();
    $payments[1]->forceFill(['charge_id'=>'ch_shared_checkout','currency'=>'PKR'])->saveQuietly();
    $this->getJson($path)->assertOk()->assertJsonPath('data.payments.total',3);
    foreach ($payments as $p) $p->forceFill(['charge_id'=>null,'payment_intent_id'=>null,'currency'=>'QAR'])->saveQuietly();
    $this->getJson($path)->assertOk()->assertJsonPath('data.payments.total',3);
});

it('supports intent-only siblings and preserves mixed refunded status without guessing a paid total', function () {
    $payments=sharedCheckoutFixture($this->externalVenue,$this->provider);
    $payments[1]->forceFill(['charge_id'=>null,'status'=>'refunded'])->saveQuietly();
    $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online')
        ->assertOk()->assertJsonPath('data.payments.data.0.status','mixed')->assertJsonPath('data.payments.data.0.amount',45)
        ->assertJsonPath('data.payments.data.0.can_request_refund',false)->assertJsonPath('data.summary.0.bookboll_received',25);
});

it('returns the same recorded staff author to booking history and payments',function(){
    $b=reportFixtureBooking($this->ownVenue,$this->provider,'manual_admin');
    $b->forceFill(['created_by'=>$this->platform->id,'creation_context'=>['version'=>1,'channel'=>'admin',
        'actor_id'=>$this->platform->id,'actor_name'=>'Recorded Front Desk','actor_kind'=>'staff','basis'=>'creation_log_admin']])->saveQuietly();
    reportFixturePayment($b,'full',100,'cash','paid');
    $this->actingAs($this->platform)->getJson('/api/admin/bookings/'.$b->id.'/crm')->assertOk()
        ->assertJsonPath('data.booking.creation_context.actor_name','Recorded Front Desk')
        ->assertJsonPath('data.booking.creation_context.channel','admin')
        ->assertJsonPath('data.booking.customer_id',(string)$this->provider->id);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&channel=admin')->assertOk()
        ->assertJsonPath('data.payments.data.0.created_by_name','Recorded Front Desk')
        ->assertJsonPath('data.payments.data.0.channel','admin');
});

it('filters booking channels using creation evidence before legacy web origins',function(){
    foreach (['admin','marketplace','unknown'] as $channel) {
        $b=reportFixtureBooking($this->ownVenue,$this->provider);
        $b->forceFill(['creation_context'=>['version'=>1,'channel'=>$channel]])->saveQuietly();
    }
    $this->actingAs($this->platform);
    foreach (['manual'=>'admin','marketplace'=>'marketplace','unknown'=>'unknown'] as $filter=>$channel) {
        $this->getJson('/api/admin/bookings/dashboard?channel='.$filter)->assertOk()
            ->assertJsonPath('data.pagination.total',1)
            ->assertJsonPath('data.bookings.0.creation_context.channel',$channel);
    }
});

it('summarizes the entire filtered set independently of pagination and excludes external pending',function(){
    $external = reportFixtureBooking($this->externalVenue,$this->provider);
    reportFixturePayment($external,'deposit',15,'online','paid');
    reportFixturePayment($external,'remaining',85,'cash','pending');
    $own = reportFixtureBooking($this->ownVenue,$this->platform,'manual_admin');
    reportFixturePayment($own,'full',100,'cash','paid');
    reportFixturePayment($own,'remaining',50,'cash','pending');
    $res = $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&records_per_page=1');
    $res->assertOk()->assertJsonPath('data.report_version',2)->assertJsonPath('data.perspective','platform')->assertJsonPath('data.payments.total',4);
    expect($res->json('data.payments.data'))->toHaveCount(1);
    expect((float)$res->json('data.summary.0.bookboll_received'))->toBe(115.0);
    expect((float)$res->json('data.summary.0.owned_pending'))->toBe(50.0);
    expect((float)$res->json('data.summary.0.provider_pending'))->toBe(85.0);
    $filtered = $this->getJson('/api/admin/payments/dashboard?report_version=2&status=paid&from_date=2026-11-10&to_date=2026-11-10');
    $filtered->assertOk()->assertJsonPath('data.payments.total',2);
    expect((float)$filtered->json('data.summary.0.owned_pending'))->toBe(0.0);
});

it('separates confirmed Bookboll receipts with missing fee splits from unresolved recipients',function(){
    $own=reportFixtureBooking($this->ownVenue,$this->platform);
    $own->forceFill(['financial_context'=>['operator'=>'bookboll','channel'=>'marketplace','ownership_basis'=>'preview_owner_confirmed_2026_11_10']])->saveQuietly();
    foreach ([['paid',100],['pending',40],['cancelled',30]] as [$status,$amount]) reportFixturePayment($own,'full',$amount,'online',$status);
    $external=reportFixtureBooking($this->externalVenue,$this->provider);
    reportFixturePayment($external,'full',10,'online','paid');
    $all=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()
        ->assertJsonPath('data.review_count',1)->assertJsonPath('data.fee_split_count',3)
        ->assertJsonPath('data.fee_split_statuses.paid',1)->assertJsonPath('data.fee_split_statuses.cancelled',1);
    expect((float)$all->json('data.summary.0.bookboll_received'))->toBe(100.0);
    expect((float)$all->json('data.summary.0.owned_pending'))->toBe(40.0);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=review')->assertOk()
        ->assertJsonPath('data.payments.total',1)->assertJsonPath('data.fee_split_count',0)
        ->assertJsonPath('data.payments.data.0.category','unclassified');
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=fee_split&status=paid')->assertOk()
        ->assertJsonPath('data.payments.total',1)->assertJsonPath('data.review_count',0)
        ->assertJsonPath('data.fee_split_count',1)->assertJsonPath('data.payments.data.0.recipient_kind','bookboll');
});

it('does not expand a provider scope through a requested foreign venue filter',function(){
    $own = reportFixtureBooking($this->ownVenue,$this->platform);
    reportFixturePayment($own,'full',100,'cash','paid');
    $external = reportFixtureBooking($this->externalVenue,$this->provider,'manual_admin');
    reportFixturePayment($external,'full',90,'cash','paid');
    $res = $this->actingAs($this->provider)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.perspective','provider')->assertJsonPath('data.payments.total',1);
    expect((float)$res->json('data.summary.0.provider_received'))->toBe(90.0);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&location_id='.$this->ownVenue->id)->assertOk()->assertJsonPath('data.payments.total',0);
});

it('includes staff collections in the provider method breakdown without counting them twice',function(){
    $b=reportFixtureBooking($this->externalVenue,$this->provider,'manual_admin');
    foreach ([['cash',100],['credit_card',20],['wire_transfer',30],['online',40]] as [$method,$amount]) {
        reportFixturePayment($b,'full',$amount,$method,'paid');
    }
    $res=$this->actingAs($this->provider)->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk();
    expect((float)$res->json('data.summary.0.provider_received'))->toBe(190.0)
        ->and((float)$res->json('data.summary.0.provider_received_offline'))->toBe(150.0)
        ->and((float)$res->json('data.summary.0.provider_received_online'))->toBe(40.0)
        ->and((float)$res->json('data.summary.0.platform_fees'))->toBe(0.0);
});

it('keeps an owners frozen ledger visible after transfer without opening the current booking',function(){
    $b=reportFixtureBooking($this->externalVenue,$this->provider,'manual_admin');
    reportFixturePayment($b,'full',100,'cash','paid');
    $this->externalVenue->forceFill(['operator_type'=>'bookboll','owner_user_id'=>null,'business_id'=>null])->save();
    $this->actingAs($this->provider)->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()
        ->assertJsonPath('data.payments.total',1)
        ->assertJsonPath('data.payments.data.0.can_view_booking',false)
        ->assertJsonPath('data.payments.data.0.can_collect',false);
    $this->getJson('/api/admin/bookings/'.$b->id.'/crm')->assertForbidden();
    $this->putJson('/api/admin/bookings/'.$b->id.'/dashboard',['notes'=>'unauthorized'])->assertForbidden();
    $foreign=reportFixtureBooking($this->externalVenue,$this->platform,'manual_admin');
    reportFixturePayment($foreign,'full',999,'cash','paid');
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.payments.total',1);
});

it('does not give a new venue owner the previous operators booking finances',function(){
    $b=reportFixtureBooking($this->ownVenue,$this->platform,'manual_admin');
    reportFixturePayment($b,'full',100,'cash','paid');
    $this->ownVenue->forceFill(['business_id'=>$this->business->id,'owner_user_id'=>$this->provider->id,'operator_type'=>'provider'])->save();
    $this->actingAs($this->provider)->getJson('/api/admin/bookings/'.$b->id.'/crm')->assertForbidden();
});

it('derives booking receipt totals without adding the online split twice',function(){
    $b=reportFixtureBooking($this->externalVenue,$this->provider);
    $root=reportFixturePayment($b,'full',100,'online','paid');
    reportFixturePayment($b,'booking_commission',15,'online','paid');
    reportFixturePayment($b,'booking_earning',85,'online','paid');
    $this->actingAs($this->platform)->getJson('/api/admin/bookings/'.$b->id.'/crm')->assertOk()
        ->assertJsonPath('data.booking.recorded_payment_summary.paid',100)
        ->assertJsonPath('data.booking.recorded_payment_summary.status','Paid');
    expect($b->fresh()->payment_status)->toBe('pending');
});

it('rejects foreign, online and already settled receipt collection requests',function(){
    $b=reportFixtureBooking($this->ownVenue,$this->platform,'manual_admin');
    $pending=reportFixturePayment($b,'full',100,'cash','pending');
    $path='/api/admin/bookings/'.$b->id.'/payments/'.$pending->id.'/collect';
    $this->actingAs($this->provider)->putJson($path,['method'=>'cash'])->assertForbidden();
    $this->actingAs($this->platform)->putJson($path,['method'=>'online'])->assertStatus(422);
    $pending->update(['method'=>'online']);
    $this->putJson($path,['method'=>'cash'])->assertStatus(422);
    $pending->update(['method'=>'cash','status'=>'refunded']);
    $this->putJson($path,['method'=>'cash'])->assertStatus(422);
    expect($pending->fresh()->status)->toBe('refunded');
});

it('records own-venue offline collection to Bookboll instead of a generic provider',function(){
    $b=reportFixtureBooking($this->ownVenue,$this->platform,'manual_admin');
    $p=reportFixturePayment($b,'full',100,'cash','pending');
    $this->actingAs($this->platform)->putJson('/api/admin/bookings/'.$b->id.'/payments/'.$p->id.'/collect',['method'=>'cash'])->assertOk();
    expect($p->fresh()->recipient)->toBe('@bookboll');
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()
        ->assertJsonPath('data.summary.0.bookboll_received',100)
        ->assertJsonPath('data.summary.0.platform_fees',0);
});

it('captures recipient and preserves historical ownership after venue changes',function(){
    $booking = reportFixtureBooking($this->externalVenue,$this->provider);
    $p = Payment::create(['booking_id'=>$booking->id,'user_id'=>$booking->user_id,'type'=>'remaining','amount'=>85,'method'=>'cash','status'=>'pending','currency'=>'QAR','payment_id'=>'qa-snapshot']);
    expect($p->fresh()->financial_context['operator'])->toBe('provider');
    expect($p->fresh()->recipient)->toBe('@report-fixture');
    $this->externalVenue->forceFill(['operator_type'=>'bookboll','owner_user_id'=>null,'business_id'=>null])->save();
    $res = $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.payments.data.0.recipient_kind','provider');
    expect((float)$res->json('data.summary.0.owned_pending'))->toBe(0.0);
});

it('rejects reversed date and amount filters instead of returning misleading totals',function(){
    $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2&from_date=2026-11-10&to_date=2026-11-01')->assertStatus(422);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&amount_min=20&amount_max=10')->assertStatus(422);
});

it('persists internal allocations for full online and full arrival payments without extra receipts', function () {
    foreach (['online','cash'] as $method) {
        $b = reportFixtureBooking($this->ownVenue, $this->platform);
        // Recreate the quote before the payment is written, as checkout does.
        $b->forceFill(['financial_context'=>array_merge($b->financial_context, [
            'fee_minor'=>1500,'net_fee_minor'=>1500,'total_minor'=>10000,'currency'=>'QAR',
            'payment_method'=>$method === 'online' ? 'online_payment' : 'cash_on_arrival',
        ])])->save();
        $p = Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>'full','amount'=>100,'method'=>$method,'status'=>'pending','currency'=>'QAR','payment_id'=>'qa-internal-'.$method]);
        expect($p->ownedAllocations()->sum('amount_minor'))->toBe('10000');
        expect($p->ownedAllocations()->where('bucket','platform')->value('amount_minor'))->toBe(1500);
        expect($p->ownedAllocations()->where('bucket','venue')->value('amount_minor'))->toBe(8500);
        expect($b->payments()->count())->toBe(1);
        $p->update(['status'=>'paid']);
        $p->update(['status'=>'paid']); // Replayed status update must not duplicate allocations.
        expect($p->ownedAllocations()->count())->toBe(2);
        if ($method === 'online') $p->forceFill(['charge_id'=>'ch_owned_full'])->saveQuietly();
    }
    $res = $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.payments.total',4);
    expect((float)$res->json('data.summary.0.bookboll_received'))->toBe(200.0);
    expect((float)$res->json('data.summary.0.platform_fees'))->toBe(30.0);
    expect((float)$res->json('data.summary.0.owned_venue_received'))->toBe(170.0);
    expect((float)$res->json('data.summary.0.owned_online'))->toBe(85.0);
    expect((float)$res->json('data.summary.0.owned_onsite'))->toBe(85.0);
    $grouped=$this->getJson('/api/admin/payments/dashboard?report_version=2&group_by=stripe&method=online')->assertOk();
    $grouped->assertJsonPath('data.payments.total',1)->assertJsonPath('data.payments.data.0.amount',100)
        ->assertJsonPath('data.payments.data.0.allocation_count',2)->assertJsonPath('data.summary.0.bookboll_received',100)
        ->assertJsonPath('data.summary.0.platform_fees',15)->assertJsonPath('data.summary.0.owned_venue_received',85);
    $fees=$this->getJson('/api/admin/payments/dashboard?report_version=2&category=owned_platform_fee');
    $fees->assertOk()->assertJsonPath('data.payments.total',2);
    expect((float)$fees->json('data.summary.0.bookboll_received'))->toBe(30.0);
});

it('keeps own-venue deposits as platform fees and the arrival balance as venue revenue', function () {
    $b = reportFixtureBooking($this->ownVenue,$this->platform);
    foreach ([['deposit',15,'online','paid'],['remaining',85,'cash','pending']] as [$type,$amount,$method,$status]) {
        Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>$type,'amount'=>$amount,'method'=>$method,'status'=>$status,'currency'=>'QAR','payment_id'=>'qa-own-'.$type]);
    }
    $res=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.payments.total',2);
    expect((float)$res->json('data.summary.0.platform_fees'))->toBe(15.0);
    expect((float)$res->json('data.summary.0.owned_venue_received'))->toBe(0.0);
    expect((float)$res->json('data.summary.0.owned_venue_pending'))->toBe(85.0);
    $b->payments()->where('type','remaining')->first()->update(['status'=>'paid']);
    $paid=$this->getJson('/api/admin/payments/dashboard?report_version=2');
    expect((float)$paid->json('data.summary.0.bookboll_received'))->toBe(100.0);
    expect((float)$paid->json('data.summary.0.owned_venue_received'))->toBe(85.0);
});

it('does not allocate internal platform fees on manual own-venue bookings', function () {
    $b=reportFixtureBooking($this->ownVenue,$this->platform,'manual_admin');
    $p=Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>'full','amount'=>100,'method'=>'cash','status'=>'paid','currency'=>'QAR','payment_id'=>'qa-manual-own']);
    expect($p->ownedAllocations()->where('bucket','platform')->count())->toBe(0);
    $res=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    expect((float)$res->json('data.summary.0.platform_fees'))->toBe(0.0);
    expect((float)$res->json('data.summary.0.owned_venue_received'))->toBe(100.0);
});

it('flags changed receipt amounts rather than silently changing saved internal allocations', function () {
    $b=reportFixtureBooking($this->ownVenue,$this->platform);
    $p=Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>'deposit','amount'=>15,'method'=>'online','status'=>'paid','currency'=>'QAR','payment_id'=>'qa-change-own']);
    $p->update(['amount'=>20]);
    $res=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.review_count',1)->assertJsonPath('data.payments.data.0.category','unclassified');
    expect($p->ownedAllocations()->sum('amount_minor'))->toBe('1500');
});

it('preserves a fractional own-site fee and separates pending and refunded internal shares', function () {
    $b=reportFixtureBooking($this->ownVenue,$this->platform);
    $b->forceFill(['financial_context'=>array_merge($b->financial_context,[
        'channel'=>'site','fee_rate'=>7.5,'fee_minor'=>1500,'net_fee_minor'=>1500,
        'total_minor'=>20000,'currency'=>'QAR','payment_method'=>'online_payment',
    ]), 'creation_context'=>array_merge($b->creation_context ?? [], ['channel'=>'site'])])->save();
    $p=Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'type'=>'full','amount'=>200,'method'=>'online','status'=>'pending','currency'=>'QAR','payment_id'=>'qa-own-site']);
    $res=$this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    expect((float)$res->json('data.summary.0.owned_platform_pending'))->toBe(15.0);
    expect((float)$res->json('data.summary.0.owned_venue_pending'))->toBe(185.0);
    $b->forceFill(['financial_context'=>array_merge($b->financial_context,['fee_rate'=>30,'fee_minor'=>6000])])->save();
    $p->update(['status'=>'paid']);
    $paid=$this->getJson('/api/admin/payments/dashboard?report_version=2&category=owned_platform_fee');
    expect((float)$paid->json('data.summary.0.owned_site_fees'))->toBe(15.0);
    expect((float)$paid->json('data.payments.data.0.fee_rate'))->toBe(7.5);
    $p->update(['status'=>'refunded']);
    $refunded=$this->getJson('/api/admin/payments/dashboard?report_version=2');
    expect((float)$refunded->json('data.summary.0.bookboll_received'))->toBe(0.0);
    expect((float)$refunded->json('data.summary.0.refunded'))->toBe(200.0);
    expect($p->ownedAllocations()->count())->toBe(2);
});
