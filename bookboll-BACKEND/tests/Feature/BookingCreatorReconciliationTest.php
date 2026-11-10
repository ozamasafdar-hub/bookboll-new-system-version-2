<?php

use App\Models\{Booking, Business, LoyaltyTier, Payment, User, Venue};
use App\Services\BookingCreatorReconciliation;
use App\Support\{BookingProvenance, PaymentReportRules, PaymentReportTotals};
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $tier=LoyaltyTier::first()->id;
    $this->staff=User::factory()->create(['name'=>'Front Desk','status'=>'active','loyalty_tier_id'=>$tier]);
    $this->customer=User::factory()->create(['name'=>'Actual Customer','status'=>'active','loyalty_tier_id'=>$tier]);
    foreach (['staff','customer'] as $r) Role::findOrCreate($r,'web');
    $this->staff->assignRole('staff'); $this->customer->assignRole('customer');
    $this->venue=Venue::create(['name'=>'Creator test court','status'=>'active','maps_url'=>'https://maps.google.com/?q=0,0','currency_override'=>'QAR']);
    $this->venue->forceFill(['operator_type'=>'bookboll'])->saveQuietly();
});

function legacyCreatorBooking(User $creator, string $operator='bookboll'): Booking {
    return Booking::withoutEvents(fn()=>Booking::create(['user_id'=>test()->customer->id,'created_by'=>$creator->id,
        'venue_id'=>test()->venue->id,'date'=>'2026-10-10','start_time'=>'10:00','end_time'=>'11:00','duration'=>1,
        'origin'=>'bookboll_web','status'=>'confirmed','payment_status'=>'fully_paid','payment_method'=>'paid_cash',
        'subtotal'=>100,'total_price'=>100,'currency'=>'QAR','commission_effective_pct'=>15,'commission_fee_minor'=>1500]))
        ->forceFill(['financial_context'=>['operator'=>$operator,'channel'=>'marketplace','fee_rate'=>15,'fee_minor'=>1500,
            'net_fee_minor'=>1500,'total_minor'=>10000,'currency'=>'QAR','business_name'=>'External business',
            'provider_recipient'=>'@external','payment_method'=>'online_payment']]);
}
function creatorPayment(Booking $b, string $status='paid', string $type='full'): Payment {
    $b->saveQuietly();
    return Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'payment_id'=>'creator-'.uniqid(),
        'amount'=>100,'currency'=>'QAR','method'=>'online','type'=>$type,'status'=>$status,
        'financial_context'=>$b->financial_context,'recipient'=>'@bookboll','date'=>'2026-10-10']));
}
function creatorReport(Booking $b): array {
    $b->refresh();
    $payments=$b->payments()->with('ownedAllocations')->get()->map(fn($p)=>$p->getAttributes()+[
        'owned_allocations'=>$p->ownedAllocations->toArray()])->map(function($p){$p['financial_context']=json_decode($p['financial_context'],true);return $p;})->all();
    $booking=$b->getAttributes(); $booking['financial_context']=$b->financial_context;
    $rows=PaymentReportRules::rows($payments,$booking); $totals=new PaymentReportTotals;
    foreach($rows as $row) $totals->add($row);
    return ['rows'=>$rows,'totals'=>$totals->result()];
}

it('uses the recorded staff author even when history user_id incorrectly points at the customer',function(){
    $b=legacyCreatorBooking($this->staff); $b->saveQuietly();
    $b->history()->create(['user_id'=>$this->customer->id,'action'=>'booking_created','description'=>'Booking created by Front Desk for QAR 100.00 — awaiting payment.']);
    $c=BookingProvenance::legacy($b->fresh());
    expect($c['channel'])->toBe('admin')->and($c['actor_id'])->toBe($this->staff->id)->and($c['actor_name'])->toBe('Front Desk');
});

it('does not infer staff creation from a later collection or current staff role',function(){
    $this->customer->assignRole('staff');
    $b=legacyCreatorBooking($this->customer); $b->saveQuietly();
    $b->history()->create(['user_id'=>$this->staff->id,'action'=>'payment_collected','description'=>'Cash collected by staff']);
    expect(BookingProvenance::legacy($b->fresh())['channel'])->toBe('marketplace');
});

it('recognizes a distinct original author on Excel imports even after their staff role is removed',function(){
    $this->staff->syncRoles('customer');
    $b=legacyCreatorBooking($this->staff); $b->saveQuietly();
    $b->history()->create(['user_id'=>$this->customer->id,'action'=>'booking_created','description'=>'Booking imported from Excel file']);
    expect(BookingProvenance::legacy($b->fresh())['channel'])->toBe('admin');
});

it('moves an owned manual receipt entirely to venue revenue without counting its old fee allocation again',function(){
    $b=legacyCreatorBooking($this->staff); $p=creatorPayment($b);
    $p->ownedAllocations()->createMany([
        ['bucket'=>'platform','amount_minor'=>1500,'currency'=>'QAR','fee_rate'=>15,'basis'=>'booking_quote'],
        ['bucket'=>'venue','amount_minor'=>8500,'currency'=>'QAR','fee_rate'=>15,'basis'=>'booking_quote'],
    ]);
    expect(creatorReport($b)['totals'][0]['bookboll_received'])->toBe(100.0);
    app(BookingCreatorReconciliation::class)->apply($b,BookingProvenance::legacy($b));
    $r=creatorReport($b);
    expect($r['totals'][0]['platform_fees'])->toBe(0.0)->and($r['totals'][0]['owned_venue_received'])->toBe(100.0)
        ->and($r['totals'][0]['bookboll_received'])->toBe(100.0)->and(count($r['rows']))->toBe(1);
    expect($p->fresh()->amount)->toBe('100.00')->and($p->fresh()->status)->toBe('paid');
    $this->assertDatabaseCount('booking_creator_corrections',2);
    expect($b->fresh()->creation_context['channel'])->toBe('admin');
    app(BookingCreatorReconciliation::class)->apply($b->fresh(),BookingProvenance::legacy($b));
    $this->assertDatabaseCount('booking_creator_corrections',2);
});

it('keeps external manual deposits entirely with the provider and preserves unpaid status',function(){
    $b=legacyCreatorBooking($this->staff,'provider'); $p=creatorPayment($b,'pending','deposit');
    app(BookingCreatorReconciliation::class)->apply($b,BookingProvenance::legacy($b));
    $r=creatorReport($b);
    expect($r['rows'][0]['category'])->toBe('provider_manual')->and($r['rows'][0]['fee_rate'])->toBe(0)
        ->and($r['totals'][0]['bookboll_received'])->toBe(0.0)->and($r['totals'][0]['provider_pending'])->toBe(100.0)
        ->and($p->fresh()->status)->toBe('pending')->and($p->fresh()->recipient)->toBe('@external');
});

it('stamps the admin endpoint on new bookings before creating the financial snapshot',function(){
    $this->actingAs($this->staff);
    app()->instance('request',Request::create('/api/admin/bookings','POST'));
    $b=Booking::create(['user_id'=>$this->customer->id,'venue_id'=>$this->venue->id,'origin'=>'bookboll_web',
        'date'=>'2026-11-11','start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,
        'status'=>'confirmed','payment_status'=>'pending','payment_method'=>'cash_on_arrival','currency'=>'QAR',
        'commission_fee_minor'=>1500,'commission_effective_pct'=>15]);
    expect($b->origin)->toBe('manual_admin')->and($b->creation_context['actor_id'])->toBe($this->staff->id)
        ->and($b->financial_context['channel'])->toBe('admin')->and($b->financial_context['fee_minor'])->toBe(0);
});

it('keeps staff using customer checkout as a customer booking',function(){
    $this->actingAs($this->staff);
    app()->instance('request',Request::create('/api/customer/bookings/create','POST'));
    $b=new Booking(['user_id'=>$this->staff->id,'created_by'=>$this->staff->id,'origin'=>'bookboll_web']);
    expect(BookingProvenance::forNew($b)['channel'])->toBe('marketplace');
});
