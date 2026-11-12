<?php
use App\Models\{Booking,Listing,Payment,Promocode,User,Venue};
use App\Services\BookingService;
use App\Support\{BookingPromotion,PaymentFinancialContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
uses(RefreshDatabase::class);
beforeEach(function(){
    $this->owner=User::factory()->create();$this->customer=User::factory()->create();
    $this->venue=Venue::create(['name'=>'QA Court','owner_user_id'=>$this->owner->id,'currency_override'=>'QAR','status'=>'active']);
    $this->listing=Listing::create(['name'=>'QA Court','venue_id'=>$this->venue->id,'status'=>'active','base_price'=>100,'capacity'=>10,'slot_duration'=>60,'commission_rate'=>20,'payment_options'=>['deposit'=>['percentage'=>20],'site_deposit_enabled'=>true]])->fresh('venue');
});
function fundedBooking(string $recipient,string $method,string $surface='marketplace',float $rate=20): Booking {
    test()->listing->update(['commission_rate'=>$rate]);
    $p=Promocode::create(['description'=>'QA promo','code'=>strtoupper(uniqid('QA')),'status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>$recipient,'owner_user_id'=>$recipient==='provider'?test()->owner->id:null,'payment_methods'=>'all']);
    $discount=BookingPromotion::discount($p,test()->listing,100,$surface,$method);
    $form=['venue_id'=>test()->venue->id,'listing_id'=>test()->listing->id,'payment_method'=>$method,'surface'=>$surface,'quantity'=>1,'add_ons'=>[], 'platform_promo_discount'=>BookingPromotion::platformFunded($p)?$discount:0];
    $b=app(BookingService::class)->createSingleBooking(test()->customer,$form,now()->addDay()->toDateString(),'10:00','11:00',100,$discount,0,$p,null,null,test()->listing,new Request());
    expect($b)->not->toBeNull(); return $b->fresh();
}
it('calculates a provider promo fee on the discounted price for both methods and surfaces',function($method,$surface){
    $b=fundedBooking('provider',$method,$surface);$context=PaymentFinancialContext::forNewBooking($b);
    expect((float)$b->total_price)->toBe(90.0)->and($context['net_fee_minor'])->toBe(1800)->and((int)$b->merchant_share_minor)->toBe(7200);
    if ($method==='deposit_remaining_on_arrival') expect((float)$b->payments()->where('type','deposit')->value('amount'))->toBe(18.0)->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(72.0);
    else expect((float)$b->payments()->where('type','full')->value('amount'))->toBe(90.0);
})->with([['online_payment','marketplace'],['deposit_remaining_on_arrival','marketplace'],['online_payment','site'],['deposit_remaining_on_arrival','site']]);
it('funds a platform promo from the fee and preserves the venue amount',function($method){
    $b=fundedBooking('bookboll',$method);$c=PaymentFinancialContext::forNewBooking($b);
    expect((float)$b->total_price)->toBe(90.0)->and($c['net_fee_minor'])->toBe(1000)->and((int)$b->merchant_share_minor)->toBe(8000);
    if($method==='deposit_remaining_on_arrival') expect((float)$b->payments()->where('type','deposit')->value('amount'))->toBe(10.0)->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(80.0);
})->with(['online_payment','deposit_remaining_on_arrival']);
it('caps the platform promo at a smaller fee',function(){
    $b=fundedBooking('bookboll','deposit_remaining_on_arrival','marketplace',5);
    expect((float)$b->total_price)->toBe(95.0)->and((float)$b->payments()->where('type','deposit')->value('amount'))->toBe(0.0)->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(95.0);
});
it('allocates unequal slot discounts without negatives or losing pennies',function(){
    expect(BookingPromotion::distribute(10,[1,99]))->toBe([0.1,9.9]);
    expect(array_sum(BookingPromotion::distribute(0.05,[1,1,1])))->toBe(0.05);
});
it('accepts deposit as online and rejects another provider venue',function(){
    $p=Promocode::create(['description'=>'QA promo','code'=>'QAONLINE','status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>'provider','owner_user_id'=>$this->owner->id,'payment_methods'=>'online_only']);
    expect($p->isValidForOrder(100,$this->customer->id,'deposit_remaining_on_arrival',$this->venue->id,$this->listing->id))->toBeTrue();
    expect($p->isValidForOrder(100,$this->customer->id,'cash_on_arrival',$this->venue->id,$this->listing->id))->toBeFalse();
    $other=Venue::create(['name'=>'Other','owner_user_id'=>User::factory()->create()->id]);
    expect($p->isValidForOrder(100,$this->customer->id,'online_payment',$other->id))->toBeFalse();
});
it('refuses comeback codes on a provider website',function(){
    $p=new Promocode(['comeback_booking_id'=>99,'recipient'=>'bookboll','discount_type'=>'percentage','discount_value'=>10]);
    expect(BookingPromotion::discount($p,$this->listing,100,'site','online_payment'))->toBe(0.0);
});

it('keeps the fixed deposit for a provider promo',function(){
    $this->listing->update(['payment_options'=>['deposit'=>['amount'=>25]]]);
    $b=fundedBooking('provider','deposit_remaining_on_arrival');
    expect((float)$b->total_price)->toBe(90.0)->and((int)$b->commission_fee_minor)->toBe(2500)
        ->and((float)$b->payments()->where('type','deposit')->value('amount'))->toBe(25.0)
        ->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(65.0);
});
it('caps a Bookboll promo at the fixed deposit and never reduces the provider remainder',function(){
    $this->listing->update(['payment_options'=>['deposit'=>['amount'=>5]]]);
    $b=fundedBooking('bookboll','deposit_remaining_on_arrival');
    expect((float)$b->total_price)->toBe(95.0)->and(PaymentFinancialContext::forNewBooking($b)['net_fee_minor'])->toBe(0)
        ->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(95.0);
});
it('preserves each fixed deposit even when one slot is cheaper than the requested discount',function(){
    $this->listing->update(['payment_options'=>['deposit'=>['amount'=>20]]]);
    $p=new Promocode(['recipient'=>'provider','discount_type'=>'percentage','discount_value'=>95]);
    $q=BookingPromotion::breakdown($p,$this->listing,[10,100],'marketplace','deposit_remaining_on_arrival');
    expect($q['parts'])->toBe([0,80])->and($q['fee_parts'])->toBe([10.0,20.0])->and($q['discount'])->toBe(80.0);
});
it('caps combined platform discounts at the sum of rounded slot fees',function(){
    $p=new Promocode(['recipient'=>'bookboll','discount_type'=>'percentage','discount_value'=>100]);
    $q=BookingPromotion::breakdown($p,$this->listing,[0.03,0.03,0.03],'marketplace','online_payment');
    expect($q['parts'])->toBe([0.01,0.01,0.01])->and($q['discount'])->toBe(0.03)->and($q['fee'])->toBe(0.0);
});
it('previews deposit promotions with the same allocation used at checkout',function(){
    $p=Promocode::create(['description'=>'QA promo','code'=>'QAPREVIEW','status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>'provider','owner_user_id'=>$this->owner->id,'payment_methods'=>'online_only']);
    $this->actingAs($this->customer,'sanctum')->postJson('/api/promocodes/apply',[
        'promocode'=>$p->code,'order_amount'=>100,'venue_id'=>$this->venue->id,'listing_id'=>$this->listing->id,'payment_method'=>'deposit_remaining_on_arrival',
    ])->assertOk()->assertJsonPath('data.promocode.discount_amount',10)->assertJsonPath('data.promocode.platform_fee',18);
});
it('cannot impersonate the customer assigned a private promo in the preview',function(){
    $p=Promocode::create(['description'=>'QA promo','code'=>'QAPRIVATE','status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>'bookboll']);$p->users()->attach($this->customer->id);
    $r=$this->actingAs(User::factory()->create(),'sanctum')->postJson('/api/promocodes/apply',[
        'promocode'=>$p->code,'order_amount'=>100,'user_id'=>$this->customer->id,'venue_id'=>$this->venue->id,'listing_id'=>$this->listing->id,'payment_method'=>'online_payment',
    ]);$r->assertJsonPath('success',false);
});
it('confirms a zero-deposit checkout while leaving the arrival balance pending without contacting Stripe',function(){
    \Illuminate\Support\Facades\Notification::fake();\Illuminate\Support\Facades\Http::preventStrayRequests();
    $this->actingAs($this->customer);
    $this->listing->update(['show_on_customer'=>true,'show_on_admin'=>true,'commission_rate'=>5,'min_advance_minutes'=>0]);
    $day=now()->addDays(3);
    foreach([null,$this->listing->id] as $lid) \App\Models\Schedule::create(['venue_id'=>$this->venue->id,'listing_id'=>$lid,'day'=>strtolower($day->format('l')),'is_open'=>true,'start_time'=>'00:00','end_time'=>'23:59']);
    $p=Promocode::create(['description'=>'QA comeback','code'=>'QAZERODEPOSIT','status'=>'active','discount_type'=>'percentage','discount_value'=>10,'recipient'=>'bookboll','payment_methods'=>'all']);
    $result=app(\App\Services\PaymentService::class)->createCheckoutSession([
        'venue_id'=>$this->venue->id,'listing_id'=>$this->listing->id,'payment_method'=>'deposit_remaining_on_arrival','promocode'=>$p->code,'add_ons'=>[],
        'slots'=>[['date'=>$day->toDateString(),'start_time'=>'10:00','end_time'=>'11:00','quantity'=>1]],
    ],$this->customer);
    $this->assertTrue($result->getData()->success,json_encode($result->getData()));
    $b=Booking::sole();expect($b->status)->toBe('confirmed')->and($b->payment_status)->toBe('deposit_paid')
        ->and($b->payments()->where('type','deposit')->value('status'))->toBe('paid')
        ->and((float)$b->payments()->where('type','remaining')->value('amount'))->toBe(95.0)
        ->and($b->payments()->where('type','remaining')->value('status'))->toBe('pending');
    expect($this->customer->fresh()->stripe_id)->toBeNull();
});
it('records the same internal fee and venue shares for Bookboll owned customer bookings',function(){
    $this->venue->update(['owner_user_id'=>null]);$this->listing->unsetRelation('venue');
    $b=fundedBooking('bookboll','online_payment');$c=PaymentFinancialContext::forNewBooking($b);
    expect($c['net_fee_minor'])->toBe(1000)->and((int)$b->merchant_share_minor)->toBe(8000);
});
