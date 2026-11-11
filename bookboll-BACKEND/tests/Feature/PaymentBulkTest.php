<?php
use App\Models\{Booking, Business, Payment, User, Venue};
use App\Support\{Capabilities, PaymentReleaseGate};
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\{Permission,Role};
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.enabled'=>false,'preview.workspace'=>false]);
    $this->seed(LoyaltyTierTableSeeder::class);
    foreach (['super_admin','admin','country_admin','platform_staff','provider_admin','staff'] as $name) {
        $role=Role::findOrCreate($name,'web');
        foreach (Capabilities::roleMap()[$name] as $permission) Permission::findOrCreate($permission,'web');
        $role->syncPermissions(Capabilities::roleMap()[$name]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->admin=User::factory()->create(['status'=>User::ACTIVE]); $this->admin->assignRole('super_admin');
    $this->customer=User::factory()->create(['status'=>User::ACTIVE]);
    $this->provider=User::factory()->create(['status'=>User::ACTIVE]); $this->provider->assignRole('provider_admin');
    $this->business=Business::create(['owner_id'=>$this->provider->id,'name'=>'QA Bulk Sports','username'=>'qa-bulk','status'=>'active','plan'=>'free']);
    $this->venue=Venue::create(['name'=>'QA bulk court','status'=>Venue::ACTIVE,'maps_url'=>'https://maps.google.com/?q=0,0','currency_override'=>'QAR','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $this->venue->forceFill(['operator_type'=>'provider','business_id'=>$this->business->id,'owner_user_id'=>$this->provider->id])->saveQuietly();
    $this->context=['operator'=>'provider','business_id'=>$this->business->id,'business_name'=>$this->business->name,'channel'=>'marketplace'];
    $this->booking=Booking::create(['user_id'=>$this->customer->id,'venue_id'=>$this->venue->id,'date'=>'2026-11-11','start_time'=>'10:00','end_time'=>'11:00',
        'subtotal'=>100,'total_price'=>100,'status'=>'pending','payment_status'=>'pending','payment_method'=>'deposit_remaining_on_arrival','origin'=>'bookboll_web','commission_channel'=>'marketplace']);
    $this->booking->forceFill(['financial_context'=>$this->context,'creation_context'=>['channel'=>'marketplace','actor_kind'=>'customer']])->saveQuietly();
    $this->payment=bulkPayment();
    $this->actingAs($this->admin);
});
function bulkPayment(array $extra=[]): Payment {
    return Payment::withoutEvents(fn()=>Payment::create(array_merge(['booking_id'=>test()->booking->id,'user_id'=>test()->customer->id,'type'=>'full','amount'=>100,'currency'=>'QAR',
        'method'=>'cash','status'=>'pending','date'=>'2026-11-11','payment_id'=>'QA-BULK-'.Payment::count(),'financial_context'=>test()->context],$extra)));
}
function bulkPlan(array $extra=[]): array {
    $input=array_merge(['row_ids'=>[(string)test()->payment->id],'action'=>'mark_paid','reason'=>'QA verified money was collected by the venue.'],$extra);
    $result=test()->postJson('/api/admin/payments/bulk-preview',$input)->assertOk();
    return $input+['fingerprint'=>$result->json('data.fingerprint')];
}
function ownBulkPayment(): void {
    $context=['operator'=>'bookboll','channel'=>'marketplace','fee_rate'=>20];
    test()->booking->forceFill(['financial_context'=>$context])->saveQuietly();
    test()->payment->forceFill(['financial_context'=>$context])->saveQuietly();
    foreach (['platform'=>2000,'venue'=>8000] as $bucket=>$amount) test()->payment->ownedAllocations()->create(['bucket'=>$bucket,'amount_minor'=>$amount,'currency'=>'QAR','fee_rate'=>20,'basis'=>'booking_quote']);
}

it('marks pending external collections paid, synchronizes the booking and records an idempotent audit',function () {
    $plan=bulkPlan();
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk()->assertJsonPath('data.payment_count',1);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk()->assertJsonPath('data.already_recorded',true);
    expect($this->payment->fresh()->status)->toBe('paid')->and($this->payment->fresh()->marked_paid_by)->toBe($this->admin->id)
        ->and($this->booking->fresh()->payment_status)->toBe('fully_paid')->and(DB::table('payment_bulk_actions')->count())->toBe(1);
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.bookboll_received',0)->assertJsonPath('data.summary.0.provider_received',100);
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/details')->assertOk()->assertJsonPath('data.bulk_history.0.actor_name',$this->admin->name);
});

it('skips paid, online, cancelled, skipped and unclassified payments without altering them',function () {
    $online=bulkPayment(['method'=>'online','type'=>'deposit']); $paid=bulkPayment(['status'=>'paid']);
    $input=['row_ids'=>[(string)$online->id,(string)$paid->id],'action'=>'mark_paid','reason'=>'QA do not manufacture online payment evidence.'];
    $result=$this->postJson('/api/admin/payments/bulk-preview',$input)->assertOk()->assertJsonCount(0,'data.changes')->assertJsonCount(2,'data.excluded');
    $this->postJson('/api/admin/payments/bulk-action',$input+['fingerprint'=>$result->json('data.fingerprint')])->assertUnprocessable();
    foreach ([['status'=>'cancelled'],['status'=>'pending','payment_method'=>'payment_skipped']] as $change) {
        $this->booking->forceFill($change)->saveQuietly();
        $this->postJson('/api/admin/payments/bulk-preview',['row_ids'=>[(string)$this->payment->id],'action'=>'mark_paid','reason'=>'QA excluded booking evidence.'])->assertOk()->assertJsonCount(0,'data.changes');
    }
    expect($online->fresh()->status)->toBe('pending')->and(DB::table('payment_bulk_actions')->count())->toBe(0);
});

it('collects only the eligible pending portion of an explicitly reviewed mixed selection',function () {
    $paid=bulkPayment(['status'=>'paid']);
    $plan=bulkPlan(['row_ids'=>[(string)$this->payment->id,(string)$paid->id]]);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk()->assertJsonPath('data.payment_count',1);
    expect(DB::table('payment_bulk_action_items')->count())->toBe(1);
});

it('rejects partial receipt collection but collects all internal allocations exactly once',function () {
    ownBulkPayment();
    $id=(string)$this->payment->id;
    $this->postJson('/api/admin/payments/bulk-preview',['row_ids'=>[$id.':venue'],'action'=>'mark_paid','reason'=>'QA only the filtered venue allocation.'])->assertOk()->assertJsonCount(0,'data.changes');
    $plan=bulkPlan(['row_ids'=>[$id.':platform',$id.':venue']]);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk()->assertJsonPath('data.payment_count',1)->assertJsonPath('data.allocation_count',2);
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.bookboll_received',100)->assertJsonPath('data.summary.0.platform_fees',20)->assertJsonPath('data.summary.0.owned_venue_received',80);
});

it('moves only a selected allocation, conserves the original receipt, and can correct it back',function () {
    ownBulkPayment(); $this->payment->forceFill(['status'=>'paid'])->saveQuietly();
    $before=$this->payment->fresh()->getAttributes(); $id=$this->payment->id.':platform';
    $plan=bulkPlan(['action'=>'reassign','row_ids'=>[$id],'destination'=>'venue_revenue']);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.total_sales',100)->assertJsonPath('data.summary.0.bookboll_received',100)->assertJsonPath('data.summary.0.platform_fees',0)->assertJsonPath('data.summary.0.owned_venue_received',100);
    foreach ($before as $key=>$value) if (!in_array($key,['financial_context','updated_at'])) expect($this->payment->fresh()->getAttributes()[$key])->toEqual($value);
    expect($this->payment->ownedAllocations()->sum('amount_minor'))->toBe('10000');
    $this->postJson('/api/admin/payments/bulk-action',bulkPlan(['action'=>'reassign','row_ids'=>[$id],'destination'=>'platform_fee']))->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.platform_fees',20)->assertJsonPath('data.summary.0.owned_venue_received',80);
});

it('uses the existing business recipient in both dashboards and hides the private audit from providers',function () {
    $this->payment->forceFill(['status'=>'paid','method'=>'online','type'=>'deposit'])->saveQuietly();
    $other=Business::create(['name'=>'Unrelated','username'=>'unrelated','status'=>'active','plan'=>'free']);
    $plan=bulkPlan(['action'=>'reassign','destination'=>'venue_revenue','business_id'=>$other->id]);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertOk();
    $this->actingAs($this->provider)->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.can_bulk_edit',false)->assertJsonPath('data.summary.0.provider_received',100)->assertJsonPath('data.payments.data.0.recipient_label','QA Bulk Sports');
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/details')->assertOk()->assertJsonPath('data.bulk_history',[]);
    expect($this->payment->fresh()->financial_context['business_id'])->toBe($this->business->id);
});

it('never assigns platform fees to staff-created bookings',function () {
    $this->booking->forceFill(['creation_context'=>['channel'=>'admin','actor_kind'=>'staff']])->saveQuietly();
    $this->postJson('/api/admin/payments/bulk-preview',['row_ids'=>[(string)$this->payment->id],'action'=>'reassign','destination'=>'platform_fee','reason'=>'QA forbidden internal fee for staff booking.'])->assertOk()->assertJsonCount(0,'data.changes');
});

it('rejects all other roles including an admin and a provider carrying superadmin',function () {
    $plan=bulkPlan();
    foreach (['admin','country_admin','platform_staff','provider_admin','staff'] as $role) {
        $actor=User::factory()->create(['status'=>User::ACTIVE]); $actor->assignRole($role);
        $this->actingAs($actor)->postJson('/api/admin/payments/bulk-preview',$plan)->assertForbidden();
        $this->postJson('/api/admin/payments/bulk-action',$plan)->assertForbidden();
    }
    $this->provider->assignRole('super_admin');
    $this->actingAs($this->provider)->postJson('/api/admin/payments/bulk-action',$plan)->assertForbidden();
    expect(DB::table('payment_bulk_actions')->count())->toBe(0);
});

it('rejects stale data or changed destinations and validates exact bounded selection',function () {
    $plan=bulkPlan(); $this->payment->forceFill(['amount'=>90])->saveQuietly();
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertConflict();
    $plan=bulkPlan(['action'=>'reassign','destination'=>'platform_fee']); $plan['destination']='venue_revenue';
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertConflict();
    $this->postJson('/api/admin/payments/bulk-preview',array_merge($plan,['row_ids'=>[(string)$this->payment->id,(string)$this->payment->id]]))->assertUnprocessable();
    $this->postJson('/api/admin/payments/bulk-preview',array_merge($plan,['row_ids'=>['99999999']]))->assertNotFound();
    $this->postJson('/api/admin/payments/bulk-preview',array_merge($plan,['row_ids'=>array_map('strval',range(1,501))]))->assertUnprocessable();
    expect($this->payment->fresh()->status)->toBe('pending')->and(DB::table('payment_bulk_actions')->count())->toBe(0);
});

it('fails closed when allocation evidence changes after a correction',function () {
    $this->payment->forceFill(['status'=>'paid'])->saveQuietly();
    $this->postJson('/api/admin/payments/bulk-action',bulkPlan(['action'=>'reassign','destination'=>'platform_fee']))->assertOk();
    $this->payment->forceFill(['amount'=>90])->saveQuietly();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.review_count',1)->assertJsonPath('data.payments.data.0.category','unclassified');
});

it('preserves a correction through a later offline collection',function () {
    $this->postJson('/api/admin/payments/bulk-action',bulkPlan(['action'=>'reassign','destination'=>'platform_fee']))->assertOk();
    $this->postJson('/api/admin/payments/bulk-action',bulkPlan())->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.platform_fees',100)->assertJsonPath('data.review_count',0);
});

it('enforces the runtime release gate before any bulk write',function () {
    $plan=bulkPlan();
    $gate=Mockery::mock(PaymentReleaseGate::class); $gate->shouldReceive('assertReady')->once()->andReturnUsing(fn()=>abort(503,'Release checks incomplete.')); app()->instance(PaymentReleaseGate::class,$gate);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertStatus(503);
    expect(DB::table('payment_bulk_actions')->count())->toBe(0)->and($this->payment->fresh()->status)->toBe('pending');
});

it('rolls back every selected receipt and audit if a later collection fails',function () {
    $second=bulkPayment(['amount'=>50]);
    $plan=bulkPlan(['row_ids'=>[(string)$this->payment->id,(string)$second->id]]);
    $real=app(\App\Services\BookingService::class); $calls=0;
    $mock=Mockery::mock(\App\Services\BookingService::class);
    $mock->shouldReceive('dashboardCollect')->twice()->andReturnUsing(function ($request,$booking,$payment) use ($real,&$calls) {
        return ++$calls === 2 ? sendError('Receipt changed',[],409) : $real->dashboardCollect($request,$booking,$payment);
    });
    app()->instance(\App\Services\BookingService::class,$mock);
    $this->postJson('/api/admin/payments/bulk-action',$plan)->assertConflict();
    expect($this->payment->fresh()->status)->toBe('pending')->and($second->fresh()->status)->toBe('pending')
        ->and(DB::table('payment_bulk_actions')->count())->toBe(0)->and(DB::table('payment_bulk_action_items')->count())->toBe(0);
});

it('keeps currencies separate and conserves the linked gross receipt during reclassification',function () {
    $this->payment->forceFill(['method'=>'online','status'=>'paid','amount'=>100])->saveQuietly();
    $context=$this->context+['receipt_ids'=>[$this->payment->id]];
    $fee=bulkPayment(['type'=>'booking_commission','amount'=>20,'method'=>'online','status'=>'paid','financial_context'=>$context]);
    $share=bulkPayment(['type'=>'booking_earning','amount'=>80,'method'=>'online','status'=>'paid','financial_context'=>$context]);
    $pkr=bulkPayment(['currency'=>'PKR','amount'=>1000,'status'=>'paid','method'=>'cash']);
    $input=['row_ids'=>[(string)$fee->id,(string)$pkr->id],'action'=>'reassign','destination'=>'venue_revenue','reason'=>'QA linked original receipt must never be counted twice.'];
    $preview=$this->postJson('/api/admin/payments/bulk-preview',$input)->assertOk()->assertJsonCount(1,'data.changes')->assertJsonCount(1,'data.excluded');
    $this->postJson('/api/admin/payments/bulk-action',$input+['fingerprint'=>$preview->json('data.fingerprint')])->assertOk();
    $totals=collect($this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->json('data.summary'))->keyBy('currency');
    expect($totals['QAR']['total_sales'])->toBe(100)->and($totals['QAR']['provider_received'])->toBe(100)
        ->and($totals['QAR']['bookboll_received'])->toBe(0)->and($totals['PKR']['total_sales'])->toBe(1000);
    expect($this->payment->fresh()->amount)->toBe('100.00')->and($share->fresh()->amount)->toBe('80.00');
});
