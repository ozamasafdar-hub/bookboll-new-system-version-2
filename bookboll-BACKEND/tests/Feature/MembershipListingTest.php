<?php
use App\Models\{User,Venue,ClassProgram,Business,BusinessSubscription,MarketSetting,MembershipListingSubscription,MembershipListingInvoice};
use App\Support\{MembershipListingPolicy as Policy,MarketSettings};
use App\Services\MembershipListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);
beforeEach(function () {
    foreach (['super_admin','provider_admin','provider_viewer'] as $role) Role::findOrCreate($role, 'web');
    $this->admin = User::factory()->create(); $this->admin->assignRole('super_admin');
    $this->owner = User::factory()->create(); $this->owner->assignRole('provider_admin');
    $this->business = Business::create(['name'=>'QA Academy','username'=>'qa-academy','owner_id'=>$this->owner->id,'plan'=>'free']);
    $this->venue = Venue::create(['name'=>'QA Academy venue','owner_user_id'=>$this->owner->id,'business_id'=>$this->business->id,'status'=>'active','phone'=>'+97455551234','maps_url'=>'https://maps.google.com/?q=0,0','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $this->program = ClassProgram::create(['name'=>'QA Football','venue_id'=>$this->venue->id,'owner_user_id'=>(string)$this->owner->id,'mapped_owner_user_id'=>$this->owner->id,'status'=>'active','is_draft'=>false,'approval_status'=>'approved','show_on_marketplace'=>true,'show_on_provider_site'=>true,'contact_phone'=>'+97455551234']);
    $this->actingAs($this->admin)->withHeader('from','admin-web');
});
function listingSub($test, array $extra=[]): MembershipListingSubscription {
 return MembershipListingSubscription::create(array_merge(['program_id'=>$test->program->id,'business_id'=>$test->business->id,'payer_id'=>$test->owner->id,'amount'=>20,'currency'=>'USD','status'=>'active','stripe_subscription_id'=>'sub_listing_qa'], $extra));
}
function listingInvoice(string $status='paid'): object {
 return \Stripe\StripeObject::constructFrom(['id'=>'in_listing_qa','status'=>$status,'currency'=>'usd','amount_due'=>2000,'amount_paid'=>$status==='paid'?2000:0,'payment_intent'=>'pi_listing_qa','created'=>now()->timestamp,'status_transitions'=>['paid_at'=>now()->timestamp],'lines'=>['data'=>[['period'=>['start'=>now()->timestamp,'end'=>now()->addMonth()->timestamp]]]],'hosted_invoice_url'=>'https://invoice.stripe.com/test']);
}
it('requires a paid listing period independently of premium and expires without a scheduler',function(){
 expect(Policy::visible($this->program->fresh()))->toBeFalse();
 $s=listingSub($this,['paid_until'=>now()->addDay()]);
 expect(Policy::visible($this->program->fresh()))->toBeTrue();
 $s->update(['status'=>'past_due']); expect(Policy::visible($this->program->fresh()))->toBeTrue();
 $s->update(['paid_until'=>now()->subSecond()]); expect(Policy::visible($this->program->fresh()))->toBeFalse();
});
it('requires an active paid premium plan for the provider website even for platform edits',function(){
 expect(Policy::visible($this->program->fresh(),(string)$this->owner->id))->toBeFalse();
 $this->business->update(['plan'=>'premium']);
 $plan=BusinessSubscription::create(['business_id'=>$this->business->id,'plan'=>'premium','billing'=>'yearly','status'=>'active','amount'=>588,'currency'=>'USD','current_period_end'=>now()->addYear()]);
 expect(Policy::visible($this->program->fresh(),(string)$this->owner->id))->toBeTrue()->and(Policy::visible($this->program->fresh()))->toBeFalse();
 $plan->update(['current_period_end'=>now()->subDay()]); expect(Policy::visible($this->program->fresh(),(string)$this->owner->id))->toBeFalse();
});
it('scopes the directory and edits to the existing business',function(){
 $other=User::factory()->create(); $other->assignRole('provider_admin');
 Business::create(['owner_id'=>$other->id,'name'=>'Other','username'=>'other-academy']);
 $this->actingAs($other);
 $this->getJson('/api/admin/membership-listings')->assertOk()->assertJsonCount(0,'data.programs');
 $this->putJson('/api/admin/subscriptions/programs/'.$this->program->id,['name'=>'Tampered'])->assertForbidden();
 $this->postJson('/api/admin/subscriptions/programs',['name'=>'Tampered','location_id'=>$this->venue->id,'contact_phone'=>'+97455551234'])->assertForbidden();
 $this->deleteJson('/api/admin/subscriptions/programs/'.$this->program->id)->assertNotFound();
});
it('creates contact listings without customers wallets or reserved sessions and rejects free website publishing',function(){
 $data=['name'=>'New academy','location_id'=>$this->venue->id,'contact_phone'=>'+97455550000','schedule_description'=>'Tuesday evenings','show_on_provider_site'=>false,'status'=>'active'];
 $this->postJson('/api/admin/subscriptions/programs',$data)->assertOk()->assertJsonPath('data.program.contact_phone','+97455550000');
 $this->assertDatabaseCount('class_subscriptions',0); $this->assertDatabaseCount('class_sessions',0); $this->assertDatabaseCount('membership_listing_subscriptions',0);
 $this->postJson('/api/admin/subscriptions/programs',array_replace($data,['show_on_provider_site'=>true]))->assertForbidden();
 $this->postJson('/api/admin/subscriptions/programs',array_replace($data,['contact_phone'=>'-------']))->assertStatus(422);
});
it('retires customer enrolment subscriber operations and checkin APIs',function(){
 $this->postJson('/api/customer/programs/'.$this->program->uuid.'/enroll',[])->assertStatus(410);
 $this->postJson('/api/admin/subscriptions/dashboard',[])->assertStatus(410);
 $this->getJson('/api/admin/subscriptions/dashboard/1')->assertStatus(410);
 $this->getJson('/api/admin/checkin/lookup')->assertStatus(410);
});
it('keeps unpaid listings out of public direct links and grants only confirmed paid access',function(){
 $this->getJson('/api/marketplace/programs/'.$this->program->uuid)->assertNotFound();
 $sub=listingSub($this); app(MembershipListingService::class)->recordInvoice($sub,listingInvoice());
 $this->getJson('/api/marketplace/programs/'.$this->program->uuid)->assertOk()->assertJsonPath('data.program.contact_phone','+97455551234');
});
it('records invoices once ignores late failures and removes access after a full refund',function(){
 $sub=listingSub($this); $service=app(MembershipListingService::class);
 $service->recordInvoice($sub,listingInvoice()); $service->recordInvoice($sub,listingInvoice()); $service->recordInvoice($sub,listingInvoice('open'));
 expect(MembershipListingInvoice::count())->toBe(1)->and(MembershipListingInvoice::first()->amount_paid)->toBe('20.00')->and(Policy::visible($this->program->fresh()))->toBeTrue();
 $event=\Stripe\Event::constructFrom(['id'=>'evt_refund','type'=>'charge.refunded','data'=>['object'=>['invoice'=>'in_listing_qa','payment_intent'=>'pi_listing_qa','amount_refunded'=>2000]]]);
 expect($service->webhook($event))->toBeTrue();
 $service->recordInvoice($sub,listingInvoice());
 expect(MembershipListingInvoice::first()->amount_refunded)->toBe('20.00')->and(Policy::visible($this->program->fresh()))->toBeFalse();
});
it('ignores stale subscription events and preserves the paid period after cancellation',function(){
 $s=listingSub($this,['paid_until'=>now()->addDay()]);$service=app(MembershipListingService::class);
 $service->syncSubscription($s,(object)['id'=>$s->stripe_subscription_id,'status'=>'canceled'],200);
 $service->syncSubscription($s,(object)['id'=>$s->stripe_subscription_id,'status'=>'active'],100);
 expect($s->fresh()->status)->toBe('canceled')->and(Policy::visible($this->program->fresh()))->toBeTrue();
});
it('preserves historical records when archiving and refuses moves while subscribed',function(){
 $s=listingSub($this); $this->deleteJson('/api/admin/subscriptions/programs/'.$this->program->id)->assertStatus(409);
 $s->update(['status'=>'canceled']); $this->deleteJson('/api/admin/subscriptions/programs/'.$this->program->id)->assertOk();
 $this->assertDatabaseHas('class_programs',['id'=>$this->program->id,'status'=>'inactive']); $this->assertDatabaseCount('membership_listing_subscriptions',1);
});
it('has a twenty dollar default and uses inherited local listing rates without changing old subscription prices',function(){
 expect(Policy::monthlyPrice($this->program))->toBe(20.0);
 $sub=listingSub($this);
 MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0],['values'=>['membership_listing_monthly'=>35]]);app(MarketSettings::class)->clear();
 expect(Policy::monthlyPrice($this->program))->toBe(35.0)->and($sub->fresh()->amount)->toBe('20.00');
});
it('never creates a charge in the railway preview',function(){
 $this->withoutMiddleware(\App\Http\Middleware\PaymentsPreviewAccess::class);
 config(['preview.enabled'=>true]);
 $this->postJson('/api/admin/membership-listings/'.$this->program->id.'/checkout',[])->assertStatus(409);
 $this->assertDatabaseCount('membership_listing_subscriptions',0);
});

it('quotes listing price on the server and reuses an open Stripe checkout without granting visibility',function(){
 config(['services.stripe.secret'=>'sk_test_listing_mock','app.admin_url'=>'https://admin.example.test','preview.enabled'=>false]);
 $fake=new class implements \Stripe\HttpClient\ClientInterface {
   public array $calls=[];
   public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1') {
     $this->calls[]=compact('method','url','headers','params');
     return [json_encode(['id'=>'cs_listing_qa','object'=>'checkout.session','status'=>'open','url'=>'https://checkout.stripe.com/test','mode'=>'subscription']),200,[]];
   }
 };
 \Stripe\ApiRequestor::setHttpClient($fake);
 try {
   $this->postJson('/api/admin/membership-listings/'.$this->program->id.'/checkout',['amount'=>1])->assertOk()->assertJsonPath('data.url','https://checkout.stripe.com/test');
   $this->postJson('/api/admin/membership-listings/'.$this->program->id.'/checkout',[])->assertOk();
   expect(MembershipListingSubscription::count())->toBe(1)->and($fake->calls[0]['params']['line_items'][0]['price_data']['unit_amount'])->toBe(2000)
    ->and($fake->calls[0]['params']['mode'])->toBe('subscription')->and($fake->calls[1]['method'])->toBe('get')->and(Policy::visible($this->program->fresh()))->toBeFalse();
 } finally { \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient()); }
});
it('routes signed listing invoice metadata before legacy customer subscription handling',function(){
 $sub=listingSub($this,['status'=>'incomplete']);
 $invoice=listingInvoice()->toArray(); $invoice['subscription']='sub_listing_qa';$invoice['metadata']=[];$invoice['parent']=['subscription_details'=>['metadata'=>['source'=>'membership_listing','listing_subscription_id'=>(string)$sub->id]]];
 $event=\Stripe\Event::constructFrom(['id'=>'evt_listing_paid','type'=>'invoice.paid','created'=>now()->timestamp,'data'=>['object'=>$invoice]]);
 app(\App\Services\StripeService::class)->handleWebhookEvent($event);
 expect(MembershipListingInvoice::count())->toBe(1)->and($sub->fresh()->status)->toBe('active')->and(Policy::visible($this->program->fresh()))->toBeTrue();
 $this->assertDatabaseCount('payments',0);$this->assertDatabaseCount('class_subscriptions',0);
});
it('inherits listing prices through country city and reset independently from booking percentages',function(){
 $country=\Nnjeim\World\Models\Country::create(['name'=>'Listing country','iso2'=>'LQ','iso3'=>'LQA','phone_code'=>'0','region'=>'Asia','subregion'=>'Asia','status'=>1]);
 $state=\Nnjeim\World\Models\State::create(['name'=>'Listing state','country_id'=>$country->id,'country_code'=>'LQA']);
 $city=\Nnjeim\World\Models\City::create(['name'=>'Listing city','country_id'=>$country->id,'country_code'=>'LQA','state_id'=>$state->id,'status'=>1]);
 $this->venue->update(['country_id'=>$country->id,'city_id'=>$city->id]);
 foreach ([['country',$country->id,15],['city',$city->id,12]] as [$scope,$id,$price]) {
   $values=array_fill_keys(array_keys(MarketSettings::DEFAULTS),null);$values['membership_listing_monthly']=$price;
   $this->putJson("/api/admin/geography/settings/$scope/$id",['values'=>$values])->assertOk();
   expect(Policy::monthlyPrice($this->program->fresh()))->toBe((float)$price);
 }
 $values=array_fill_keys(array_keys(MarketSettings::DEFAULTS),null);
 $this->putJson('/api/admin/geography/settings/city/'.$city->id,['values'=>$values])->assertOk();
 expect(Policy::monthlyPrice($this->program->fresh()))->toBe(15.0);
 $values['membership_listing_monthly']=0;$this->putJson('/api/admin/geography/settings/city/'.$city->id,['values'=>$values])->assertStatus(422);
});

it('retires membership wallet mutations even when only its ID is supplied', function () {
 $wallet=\App\Models\ProviderWallet::create(['owner_user_id'=>$this->owner->id,'currency'=>'USD','purpose'=>'subscription','status'=>'active']);
 $this->putJson('/api/admin/wallet-payouts/wallets/'.$wallet->id,['available_balance'=>999])->assertStatus(410);
 $this->deleteJson('/api/admin/wallet-payouts/wallets/'.$wallet->id)->assertStatus(410);
 $this->postJson('/api/admin/wallet-payouts/delta',['wallet_id'=>$wallet->id,'available'=>999])->assertStatus(410);
 $this->assertDatabaseHas('provider_wallets',['id'=>$wallet->id,'purpose'=>'subscription']);
});

it('allows an authorized manager to create a listing for a business without an owner', function () {
 Role::findOrCreate('facility_admin','web');
 $manager=User::factory()->create(); $manager->assignRole('facility_admin');
 $this->business->update(['owner_id'=>null]); $this->venue->forceFill(['owner_user_id'=>null,'business_id'=>$this->business->id])->saveQuietly();
 \App\Models\BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$manager->id,'role'=>'staff','scope'=>'all','status'=>'active','version'=>1]);
 $this->actingAs($manager);
 $response=$this->postJson('/api/admin/subscriptions/programs',['name'=>'Ownerless academy','location_id'=>$this->venue->id,'contact_phone'=>'+97455551234','show_on_provider_site'=>false,'status'=>'active']);
 $this->assertSame(200,$response->status(),(string)$response->json('message'));
 $this->getJson('/api/admin/membership-listings')->assertOk()->assertJsonCount(2,'data.programs');
});
