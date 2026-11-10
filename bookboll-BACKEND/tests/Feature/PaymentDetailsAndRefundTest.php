<?php
use App\Models\{Booking, Business, BusinessMembership, LoyaltyTier, Payment, PaymentRefund, User, Venue};
use App\Services\{PaymentRefundService, StripeRefundGateway};
use App\Support\Capabilities;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\{Permission, Role};
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

class FakePaymentRefundGateway extends StripeRefundGateway
{
    public int $calls = 0;
    public string $resultStatus = 'succeeded';
    public bool $timeout = false;
    public array $data = ['id'=>'ch_refund_fixture','paid'=>true,'captured'=>true,'disputed'=>false,'currency'=>'qar',
        'amount'=>10000,'amount_refunded'=>0,'payment_intent'=>'pi_fixture','receipt_url'=>'https://pay.stripe.com/receipts/fixture', 'refunds'=>['data'=>[]]];
    public function charge(?string $chargeId, ?string $intentId, ?string $account = null): array { return $this->data; }
    public function create(array $charge, string $reason, string $key, ?string $account): array {
        $this->calls++;
        $result = ['id'=>'re_fixture','charge'=>$charge['id'],'amount'=>$charge['amount'],'currency'=>$charge['currency'],
            'status'=>$this->resultStatus,'metadata'=>['bookboll_refund_key'=>$key], 'created'=>time()];
        $this->data['refunds']['data'] = [$result];
        if ($result['status'] === 'succeeded') $this->data['amount_refunded'] = $charge['amount'];
        if ($this->timeout) throw new RuntimeException('Simulated processor response timeout');
        return $result;
    }
}

beforeEach(function () {
    config(['preview.enabled'=>false, 'preview.workspace'=>false]);
    $this->seed(LoyaltyTierTableSeeder::class);
    $tier = LoyaltyTier::where('slug','bronze')->value('id');
    foreach (['super_admin','admin','country_admin','platform_staff','provider_admin','facility_admin','staff'] as $name) {
        $role = Role::findOrCreate($name,'web');
        foreach (Capabilities::roleMap()[$name] as $permission) Permission::findOrCreate($permission,'web');
        $role->syncPermissions(Capabilities::roleMap()[$name]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->admin = User::factory()->create(['status'=>User::ACTIVE,'loyalty_tier_id'=>$tier]);
    $this->admin->assignRole('super_admin');
    $this->customer = User::factory()->create(['status'=>User::ACTIVE,'loyalty_tier_id'=>$tier]);
    $this->venue = Venue::create(['name'=>'Refund test court','status'=>Venue::ACTIVE,'maps_url'=>'https://maps.google.com/?q=0,0',
        'currency_override'=>'QAR','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $this->venue->forceFill(['operator_type'=>'bookboll'])->save();
    $this->booking = Booking::create(['user_id'=>$this->customer->id,'venue_id'=>$this->venue->id,'date'=>'2026-11-11',
        'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,'status'=>'confirmed','payment_status'=>'fully_paid',
        'payment_method'=>'online_payment','origin'=>'bookboll_web','commission_channel'=>'marketplace','commission_effective_pct'=>20]);
    $this->context = ['version'=>1,'operator'=>'bookboll','channel'=>'marketplace','fee_rate'=>20,'fee_minor'=>2000,
        'total_minor'=>10000,'currency'=>'QAR','payment_method'=>'online_payment'];
    $this->booking->forceFill(['financial_context'=>$this->context,'creation_context'=>['version'=>1,'channel'=>'marketplace','actor_kind'=>'customer']])->saveQuietly();
    $this->payment = Payment::withoutEvents(fn () => Payment::create(['booking_id'=>$this->booking->id,'user_id'=>$this->customer->id,
        'type'=>'full','amount'=>100,'currency'=>'QAR','method'=>'online','status'=>'paid','date'=>'2026-11-11','paid_at'=>'2026-11-11 12:00:00',
        'payment_id'=>'PAY-REFUND-TEST','charge_id'=>'ch_refund_fixture','financial_context'=>$this->context]));
    $this->payment->ownedAllocations()->createMany([
        ['bucket'=>'platform','amount_minor'=>2000,'currency'=>'QAR','fee_rate'=>20,'basis'=>'saved_quote'],
        ['bucket'=>'venue','amount_minor'=>8000,'currency'=>'QAR','fee_rate'=>20,'basis'=>'saved_quote']]);
    $this->gateway = new FakePaymentRefundGateway;
    app()->instance(StripeRefundGateway::class,$this->gateway);
    $this->actingAs($this->admin);
});

function refundPreviewForTest(): string {
    return test()->getJson('/api/admin/payments/'.test()->payment->id.'/refund-preview')->assertOk()->json('data.fingerprint');
}
function submitRefundForTest(string $fingerprint) {
    return test()->postJson('/api/admin/payments/'.test()->payment->id.'/refund', ['fingerprint'=>$fingerprint,'reason'=>'requested_by_customer']);
}

it('loads related allocations independently of table filters and preserves original amount', function () {
    $response = $this->getJson('/api/admin/payments/'.$this->payment->id.'/details?row_id='.$this->payment->id.':platform')->assertOk();
    expect($response->json('data.payment.amount'))->toEqual(20)
        ->and($response->json('data.connected_transactions.0.amount'))->toEqual(80)
        ->and($response->json('data.payment.receipt_amount'))->toEqual(100)
        ->and($response->json('data.can_request_refund'))->toBeTrue();
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/details?row_id=999999:platform')->assertNotFound();
});

it('refunds the full original charge once without changing on-site balances or original amounts', function () {
    $arrival = Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$this->booking->id,'user_id'=>$this->customer->id,'type'=>'remaining',
        'amount'=>50,'currency'=>'QAR','method'=>'cash','status'=>'pending','payment_id'=>'ARRIVAL','financial_context'=>$this->context]));
    $fingerprint = refundPreviewForTest();
    submitRefundForTest($fingerprint)->assertOk()->assertJsonPath('data.status','succeeded');
    submitRefundForTest($fingerprint)->assertOk()->assertJsonPath('data.status','succeeded');
    expect($this->gateway->calls)->toBe(1)->and(PaymentRefund::count())->toBe(1)
        ->and($this->payment->fresh()->status)->toBe('refunded')->and($this->payment->fresh()->amount)->toBe('100.00')
        ->and($this->payment->fresh()->paid_at->format('Y-m-d H:i:s'))->toBe('2026-11-11 12:00:00')
        ->and($arrival->fresh()->status)->toBe('pending')->and($this->booking->fresh()->status)->toBe('confirmed');
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/details?row_id='.$this->payment->id.':platform')->assertOk()
        ->assertJsonPath('data.refund.status','succeeded')->assertJsonPath('data.refund.receipt_url','https://pay.stripe.com/receipts/fixture')
        ->assertJsonPath('data.can_request_refund',false);
    $this->getJson('/api/admin/payments/dashboard?report_version=2&status=refunded')->assertOk()
        ->assertJsonPath('data.summary.0.bookboll_received',0)->assertJsonPath('data.payments.total',2);
});

it('rejects provider accounts and non-admin platform staff even with payment permissions', function () {
    foreach (['provider_admin','facility_admin','staff','platform_staff','country_admin'] as $role) {
        $actor = User::factory()->create(['status'=>User::ACTIVE]); $actor->assignRole($role);
        $this->actingAs($actor)->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertForbidden();
        $this->postJson('/api/admin/payments/'.$this->payment->id.'/refund',['fingerprint'=>str_repeat('a',64),'reason'=>'duplicate'])->assertForbidden();
        $this->postJson('/api/admin/payments/'.$this->payment->id.'/refund-refresh')->assertForbidden();
    }
    expect($this->gateway->calls)->toBe(0);
});

it('also refuses a provider identity carrying an admin role', function () {
    $actor = User::factory()->create(['status'=>User::ACTIVE,'owner_user_id'=>$this->customer->id]); $actor->assignRole('admin');
    $this->actingAs($actor)->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertForbidden();
});

it('does not expose another business connected payment or allow forged detail IDs', function () {
    $provider = User::factory()->create(['status'=>User::ACTIVE]); $provider->assignRole('provider_admin');
    $business = Business::create(['owner_id'=>$provider->id,'name'=>'Private business','username'=>'private-refund','status'=>'active','plan'=>'free']);
    $this->actingAs($provider)->getJson('/api/admin/payments/'.$this->payment->id.'/details')->assertNotFound();
    $this->venue->forceFill(['owner_user_id'=>$provider->id,'business_id'=>$business->id,'operator_type'=>'provider'])->save();
    $this->payment->forceFill(['financial_context'=>array_merge($this->context,['operator'=>'provider','business_id'=>$business->id])])->saveQuietly();
    $other = Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$this->booking->id,'user_id'=>$this->customer->id,'type'=>'remaining',
        'amount'=>90,'currency'=>'QAR','method'=>'cash','status'=>'paid','payment_id'=>'OTHER-BUSINESS',
        'financial_context'=>['operator'=>'provider','business_id'=>$business->id+1]]));
    $response = $this->getJson('/api/admin/payments/'.$this->payment->id.'/details')->assertOk();
    expect(collect($response->json('data.connected_transactions'))->pluck('payment_id')->all())->not->toContain('OTHER-BUSINESS');
    $this->getJson('/api/admin/payments/'.$other->id.'/details')->assertNotFound();
});

it('keeps pending and failed refunds out of refunded payment status', function () {
    $this->gateway->resultStatus = 'pending';
    submitRefundForTest(refundPreviewForTest())->assertOk()->assertJsonPath('data.status','pending');
    expect($this->payment->fresh()->status)->toBe('paid');
    $this->gateway->data['refunds']['data'][0]['status'] = 'failed';
    $this->postJson('/api/admin/payments/'.$this->payment->id.'/refund-refresh')->assertOk()->assertJsonPath('data.refund.status','failed');
    expect($this->payment->fresh()->status)->toBe('paid');
});

it('recovers an accepted refund after a timeout without another money-moving request', function () {
    $this->gateway->timeout = true;
    submitRefundForTest(refundPreviewForTest())->assertOk()->assertJsonPath('data.status','unknown');
    expect($this->payment->fresh()->status)->toBe('paid');
    $this->postJson('/api/admin/payments/'.$this->payment->id.'/refund-refresh')->assertOk()->assertJsonPath('data.refund.status','succeeded');
    expect($this->payment->fresh()->status)->toBe('refunded')->and($this->gateway->calls)->toBe(1);
});

it('updates confirmed refunds through replay-safe processor events', function () {
    $this->gateway->resultStatus = 'pending';
    submitRefundForTest(refundPreviewForTest())->assertOk();
    $this->gateway->data['refunds']['data'][0]['status'] = 'succeeded';
    $event = \Stripe\Event::constructFrom(['id'=>'evt_refund','type'=>'refund.updated','data'=>['object'=>['charge'=>'ch_refund_fixture']]]);
    app(PaymentRefundService::class)->webhook($event);
    app(PaymentRefundService::class)->webhook($event);
    expect($this->payment->fresh()->status)->toBe('refunded')->and(PaymentRefund::count())->toBe(1)->and($this->gateway->calls)->toBe(1);
});

it('rejects changed payment facts, mismatched processor amounts and previous partial refunds', function () {
    $fingerprint = refundPreviewForTest();
    $this->payment->forceFill(['financial_context'=>array_merge($this->context,['business_name'=>'Updated label'])])->saveQuietly();
    submitRefundForTest($fingerprint)->assertStatus(409);
    $this->gateway->data['amount'] = 10100;
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(422);
    $this->gateway->data['amount'] = 10000; $this->gateway->data['amount_refunded'] = 1000;
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(409);
    expect($this->gateway->calls)->toBe(0);
});

it('blocks cash, unpaid and disputed payments from refunds', function () {
    $this->payment->forceFill(['method'=>'cash'])->saveQuietly();
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(422);
    $this->payment->forceFill(['method'=>'online','status'=>'pending'])->saveQuietly();
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(422);
    $this->payment->forceFill(['status'=>'paid'])->saveQuietly(); $this->gateway->data['disputed'] = true;
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(422);
});

it('blocks real refunds in the workspace even when a gateway is configured', function () {
    config(['preview.enabled'=>true, 'preview.workspace'=>true, 'preview.admin_email'=>$this->admin->email]);
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(409);
    expect($this->gateway->calls)->toBe(0)->and(PaymentRefund::count())->toBe(0);
});

it('inherits the original Stripe receipt on external allocation rows and refunds the complete split', function (bool $explicit) {
    $this->payment->ownedAllocations()->delete();
    $context = array_merge($this->context, ['operator'=>'provider','business_id'=>987]);
    $this->payment->forceFill(['financial_context'=>$context,'stripe_receipt_url'=>'https://pay.stripe.com/receipts/fixture'])->saveQuietly();
    $children = collect();
    foreach (['booking_commission'=>20,'booking_earning'=>80] as $type=>$amount) {
        $children->push(Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$this->booking->id,'user_id'=>$this->customer->id,
            'type'=>$type,'amount'=>$amount,'currency'=>'QAR','method'=>'online','status'=>'paid','date'=>'2026-11-11',
            'payment_id'=>$type,'financial_context'=>$explicit ? $context+['receipt_ids'=>[$this->payment->id]] : $context])));
    }
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()
        ->assertJsonPath('data.payments.total',2)->assertJsonPath('data.payments.data.0.stripe_receipt_url','https://pay.stripe.com/receipts/fixture');
    $this->getJson('/api/admin/payments/'.$children->first()->id.'/details')->assertOk()
        ->assertJsonPath('data.refund_source_payment_id',$this->payment->id)
        ->assertJsonCount(2,'data.connected_transactions');
    submitRefundForTest(refundPreviewForTest())->assertOk();
    expect($this->payment->fresh()->status)->toBe('refunded');
    foreach ($children as $child) expect($child->fresh()->status)->toBe('refunded');
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()
        ->assertJsonPath('data.payments.total',2)->assertJsonPath('data.summary.0.bookboll_received',0)
        ->assertJsonPath('data.payments.data.0.stripe_refund_receipt_url','https://pay.stripe.com/receipts/fixture');
    // Even when opened via a child without Stripe IDs, reveal another booking
    // settled by this charge and its separately stored fee/share records.
    $otherBooking = $this->booking->replicate(); $otherBooking->uuid = (string)\Illuminate\Support\Str::uuid();
    $otherBooking->booking_id = 'BK-OTHER-CHARGE'; $otherBooking->saveQuietly();
    $otherRoot = $this->payment->replicate(); $otherRoot->booking_id = $otherBooking->id; $otherRoot->payment_id = 'OTHER-ROOT'; $otherRoot->saveQuietly();
    foreach ($children as $child) {
        $copy = $child->replicate(); $copy->booking_id = $otherBooking->id; $copy->payment_id = 'OTHER-'.$child->type;
        $copy->financial_context = $context + ['receipt_ids'=>[$otherRoot->id]]; $copy->saveQuietly();
    }
    $this->getJson('/api/admin/payments/'.$children->first()->id.'/details')->assertOk()
        ->assertJsonCount(5,'data.connected_transactions');
})->with(['explicit receipt links'=>[true], 'conserving legacy pair'=>[false]]);

it('reviews and refunds all bookings sharing one Stripe charge as one operation', function () {
    $this->payment->ownedAllocations()->delete();
    $context = array_merge($this->context,['total_minor'=>5000,'fee_minor'=>1000]);
    $this->payment->forceFill(['amount'=>50,'financial_context'=>$context])->saveQuietly();
    $secondBooking = $this->booking->replicate(); $secondBooking->uuid = (string)\Illuminate\Support\Str::uuid(); $secondBooking->booking_id = 'BK-SECOND-REFUND'; $secondBooking->saveQuietly();
    $second = $this->payment->replicate(); $second->booking_id = $secondBooking->id; $second->payment_id = 'SECOND-CHARGE-RECORD'; $second->saveQuietly();
    foreach ([$this->payment,$second] as $payment) $payment->ownedAllocations()->createMany([
        ['bucket'=>'platform','amount_minor'=>1000,'currency'=>'QAR','fee_rate'=>20,'basis'=>'saved_quote'],
        ['bucket'=>'venue','amount_minor'=>4000,'currency'=>'QAR','fee_rate'=>20,'basis'=>'saved_quote']]);
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/details?row_id='.$this->payment->id.':platform')->assertOk()
        ->assertJsonCount(3,'data.connected_transactions');
    $preview = $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertOk()
        ->assertJsonCount(2,'data.payments')->assertJsonPath('data.amount',100);
    submitRefundForTest($preview->json('data.fingerprint'))->assertOk();
    expect($this->gateway->calls)->toBe(1)->and($second->fresh()->status)->toBe('refunded');
});

it('blocks legacy provider wallet credits that cannot be tied to an exact receipt reversal', function () {
    \App\Models\WalletTransaction::withoutEvents(fn()=>\App\Models\WalletTransaction::create([
        'uuid'=>(string)\Illuminate\Support\Str::uuid(),'owner_user_id'=>$this->customer->id,'type'=>'booking_online','direction'=>'credit',
        'source_id'=>(string)$this->booking->id,'amount'=>100,'provider_share'=>80,'platform_fee'=>20,'currency'=>'QAR','status'=>'completed']));
    $this->getJson('/api/admin/payments/'.$this->payment->id.'/refund-preview')->assertStatus(409);
    $this->postJson('/api/admin/payments/'.$this->payment->id.'/refund',['fingerprint'=>str_repeat('a',64),'reason'=>'duplicate'])->assertStatus(409);
    expect($this->gateway->calls)->toBe(0)->and(PaymentRefund::count())->toBe(0);
});

it('allows a platform admin and preserves original collection timestamps', function () {
    $this->admin->syncRoles(['admin']);
    $before = $this->payment->fresh()->updated_at->toDateTimeString();
    $this->travel(1)->days();
    submitRefundForTest(refundPreviewForTest())->assertOk()->assertJsonPath('data.status','succeeded');
    expect($this->payment->fresh()->updated_at->toDateTimeString())->toBe($before)
        ->and($this->payment->fresh()->paymentRefund->requested_by)->toBe($this->admin->id);
    $this->travelBack();
});
