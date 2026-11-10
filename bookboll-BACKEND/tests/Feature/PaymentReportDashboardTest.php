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
    }
    $res = $this->actingAs($this->platform)->getJson('/api/admin/payments/dashboard?report_version=2');
    $res->assertOk()->assertJsonPath('data.payments.total',4);
    expect((float)$res->json('data.summary.0.bookboll_received'))->toBe(200.0);
    expect((float)$res->json('data.summary.0.platform_fees'))->toBe(30.0);
    expect((float)$res->json('data.summary.0.owned_venue_received'))->toBe(170.0);
    expect((float)$res->json('data.summary.0.owned_online'))->toBe(85.0);
    expect((float)$res->json('data.summary.0.owned_onsite'))->toBe(85.0);
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
