<?php
use App\Models\{Booking, Business, Payment, User, Venue};
use App\Support\{Capabilities, PaymentReleaseGate};
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\{Permission, Role};
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
    $this->business=Business::create(['owner_id'=>$this->provider->id,'name'=>'QA Review Sports','username'=>'qa-review','status'=>'active','plan'=>'free']);
    $this->venue=Venue::create(['name'=>'QA review court','status'=>Venue::ACTIVE,'maps_url'=>'https://maps.google.com/?q=0,0','currency_override'=>'QAR','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $this->venue->forceFill(['operator_type'=>'provider','business_id'=>$this->business->id,'owner_user_id'=>$this->provider->id])->saveQuietly();
    $context=['operator'=>'provider','business_id'=>$this->business->id,'business_name'=>$this->business->name,'channel'=>'marketplace'];
    $this->booking=Booking::create(['user_id'=>$this->customer->id,'venue_id'=>$this->venue->id,'date'=>'2026-11-11','start_time'=>'10:00','end_time'=>'11:00',
        'subtotal'=>549,'total_price'=>549,'status'=>'cancelled','payment_status'=>'cancelled','payment_method'=>'online_payment','origin'=>'bookboll_web','commission_channel'=>'marketplace']);
    $this->booking->forceFill(['financial_context'=>$context,'creation_context'=>['channel'=>'marketplace','actor_kind'=>'customer']])->saveQuietly();
    $this->payment=Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$this->booking->id,'user_id'=>$this->customer->id,'type'=>'full','amount'=>549,'currency'=>'QAR',
        'method'=>'online','status'=>'cancelled','date'=>'2026-11-11','payment_id'=>'QA-REVIEW-1','financial_context'=>$context]));
    $this->actingAs($this->admin);
    $this->endpoint='/api/admin/payments/'.$this->payment->id;
});

function reviewPlan(string $action='cancelled_uncollected',array $extra=[]): array {
    $input=['action'=>$action,'reason'=>'QA evidence checked against the original booking.']+$extra;
    $response=test()->postJson(test()->endpoint.'/review-preview',$input)->assertOk();
    return $input+['fingerprint'=>$response->json('data.fingerprint')];
}
function reviewPaid(): void {
    test()->payment->forceFill(['status'=>'paid','paid_at'=>'2026-11-11 12:00:00','charge_id'=>'ch_qa_review'])->saveQuietly();
    test()->booking->forceFill(['status'=>'confirmed','payment_status'=>'fully_paid'])->saveQuietly();
}

it('resolves cancelled uncollected records without changing any original financial fields and can reopen them',function () {
    $before=$this->payment->fresh()->getAttributes();
    $this->getJson($this->endpoint.'/details')->assertOk()->assertJsonPath('data.review.options.0','cancelled_uncollected');
    $plan=reviewPlan();
    $this->postJson($this->endpoint.'/review',$plan)->assertOk()->assertJsonPath('data.already_recorded',false);
    $this->postJson($this->endpoint.'/review',$plan)->assertOk()->assertJsonPath('data.already_recorded',true);
    expect(DB::table('payment_review_decisions')->count())->toBe(1);
    foreach ($before as $key=>$value) if ($key !== 'financial_context') expect($this->payment->fresh()->getAttributes()[$key])->toEqual($value);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=review')->assertOk()->assertJsonPath('data.payments.total',0)->assertJsonPath('data.review_count',0);
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.payments.data.0.category','cancelled_uncollected')->assertJsonPath('data.summary',[]);
    $this->getJson($this->endpoint.'/details')->assertOk()->assertJsonPath('data.review.resolved',true)->assertJsonPath('data.review.history.0.actor_name',$this->admin->name);
    $this->postJson($this->endpoint.'/review',reviewPlan('reopen'))->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=review')->assertOk()->assertJsonPath('data.payments.total',1);
    expect(DB::table('payment_review_decisions')->count())->toBe(2);
});

it('records a note without hiding the review or changing totals',function () {
    $original=$this->payment->fresh()->getAttributes();
    $this->postJson($this->endpoint.'/review',reviewPlan('note'))->assertOk();
    expect($this->payment->fresh()->getAttributes())->toBe($original);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=review')->assertOk()->assertJsonPath('data.review_count',1);
});

it('rejects cancellation closure when there is collection evidence',function ($field,$value) {
    $this->payment->forceFill([$field=>$value])->saveQuietly();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'cancelled_uncollected','reason'=>'No money received per review.'])->assertUnprocessable();
})->with([['paid_at','2026-11-11 12:00:00'],['charge_id','ch_existing'],['payment_intent_id','pi_existing'],['marked_paid_by',1],['stripe_receipt_url','https://pay.stripe.com/receipts/qa'],['status','paid']]);

it('rejects stale previews after another decision or changed source evidence',function () {
    $old=reviewPlan();
    $this->postJson($this->endpoint.'/review',reviewPlan('note'))->assertOk();
    $this->postJson($this->endpoint.'/review',$old)->assertConflict();
    $new=reviewPlan();
    $this->payment->forceFill(['amount'=>449])->saveQuietly();
    $this->postJson($this->endpoint.'/review',$new)->assertConflict();
});

it('reopens a resolved record automatically if source evidence changes',function () {
    $this->postJson($this->endpoint.'/review',reviewPlan())->assertOk();
    $this->payment->forceFill(['amount'=>450])->saveQuietly();
    $this->getJson($this->endpoint.'/details')->assertOk()->assertJsonPath('data.review.stale',true)->assertJsonPath('data.payment.category','unclassified');
    $this->getJson('/api/admin/payments/dashboard?report_version=2&scope=review')->assertOk()->assertJsonPath('data.review_count',1);
});

it('conserves a paid external receipt using an exact recorded fee and scopes provider reporting',function () {
    reviewPaid();
    $plan=reviewPlan('provider_split',['fee_amount'=>'49.50']);
    $this->postJson($this->endpoint.'/review',$plan)->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.bookboll_received',49.5)
        ->assertJsonPath('data.summary.0.platform_fees',49.5)->assertJsonPath('data.summary.0.provider_received',499.5)->assertJsonPath('data.payments.total',2);
    $this->getJson($this->endpoint.'/details')->assertOk()->assertJsonPath('data.payment.receipt_amount','549.00');
    expect($this->payment->fresh()->amount)->toBe('549.00')->and($this->payment->fresh()->type)->toBe('full');
    $this->actingAs($this->provider)->getJson($this->endpoint.'/details')->assertOk()->assertJsonPath('data.review',null)->assertJsonPath('data.can_request_refund',false);
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.perspective','provider')->assertJsonPath('data.summary.0.provider_received',499.5);
});

it('can confirm a paid provider receipt was entirely a retained deposit without creating another payment',function () {
    reviewPaid();
    $this->postJson($this->endpoint.'/review',reviewPlan('retained_deposit'))->assertOk();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary.0.bookboll_received',549)->assertJsonPath('data.summary.0.provider_received',0);
    expect(Payment::count())->toBe(1);
});

it('does not create empty allocations for a zero receipt',function () {
    reviewPaid(); $this->payment->forceFill(['amount'=>0])->saveQuietly();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'provider_split','fee_amount'=>0,'reason'=>'Attempt to split a zero receipt.'])->assertUnprocessable();
});

it('refuses invalid fee precision and fees above the receipt',function ($amount) {
    reviewPaid();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'provider_split','fee_amount'=>$amount,'reason'=>'Use the original fee evidence.'])->assertUnprocessable();
})->with(['550','-1','49.501']);

it('never gives staff bookings a platform fee or silently resolves missing ownership',function () {
    reviewPaid();
    $this->booking->forceFill(['creation_context'=>['channel'=>'admin','actor_kind'=>'staff']])->saveQuietly();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'provider_split','fee_amount'=>20,'reason'=>'Attempt to override staff booking.'])->assertUnprocessable();
    $this->payment->forceFill(['financial_context'=>['operator'=>'unknown']])->saveQuietly();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'retained_deposit','reason'=>'Attempt to assume historical owner.'])->assertUnprocessable();
    $this->postJson($this->endpoint.'/review',reviewPlan('note'))->assertOk();
});

it('does not bypass skipped bookings or existing linked split records',function () {
    reviewPaid();
    $this->booking->forceFill(['payment_method'=>'payment_skipped'])->saveQuietly();
    $this->postJson($this->endpoint.'/review-preview',['action'=>'retained_deposit','reason'=>'Attempt to hide conflicting evidence.'])->assertUnprocessable();
    $this->booking->forceFill(['payment_method'=>'online_payment'])->saveQuietly();
    Payment::withoutEvents(fn()=>Payment::create(['payment_id'=>'QA-SPLIT-CHILD','user_id'=>$this->customer->id,'booking_id'=>$this->booking->id,'type'=>'booking_commission','amount'=>10,'currency'=>'QAR','status'=>'paid','method'=>'online']));
    $this->postJson($this->endpoint.'/review-preview',['action'=>'provider_split','fee_amount'=>20,'reason'=>'Attempt to duplicate another split.'])->assertUnprocessable();
});

it('restricts reviews to platform admins even when a provider also carries the admin role',function () {
    $plan=reviewPlan();
    foreach (['provider_admin','platform_staff','country_admin','staff'] as $role) {
        $actor=User::factory()->create(['status'=>User::ACTIVE]); $actor->assignRole($role);
        $this->actingAs($actor)->postJson($this->endpoint.'/review-preview',$plan)->assertForbidden();
        $this->postJson($this->endpoint.'/review',$plan)->assertForbidden();
    }
    $this->provider->assignRole('admin');
    $this->actingAs($this->provider)->postJson($this->endpoint.'/review',$plan)->assertForbidden();
    $actor=User::factory()->create(['status'=>User::ACTIVE]); $actor->assignRole('admin');
    $this->actingAs($actor)->postJson($this->endpoint.'/review-preview',array_diff_key($plan,['fingerprint'=>1]))->assertOk();
    expect(DB::table('payment_review_decisions')->count())->toBe(0);
});

it('requires a reason and binds confirmation to the exact reviewed action and amount',function () {
    $this->postJson($this->endpoint.'/review-preview',['action'=>'cancelled_uncollected','reason'=>'   '])->assertUnprocessable();
    $plan=reviewPlan(); $plan['reason']='A different reason after the preview.';
    $this->postJson($this->endpoint.'/review',$plan)->assertConflict();
    expect(DB::table('payment_review_decisions')->count())->toBe(0);
});

it('keeps runtime release checks on the save path',function () {
    $plan=reviewPlan();
    $this->mock(PaymentReleaseGate::class,function ($mock) { $mock->shouldReceive('assertReady')->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException(503,'Release blocked')); });
    $this->postJson($this->endpoint.'/review',$plan)->assertStatus(503);
    expect(DB::table('payment_review_decisions')->count())->toBe(0);
});
