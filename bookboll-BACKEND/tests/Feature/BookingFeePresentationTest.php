<?php

use App\Models\{Business,BusinessMembership,BusinessSubscription,Listing,MarketSetting,User,Venue};
use App\Services\{BookingService,MarketplaceCatalogService,PaymentService};
use App\Support\MarketSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Nnjeim\World\Models\{City,Country,State};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('provider_admin','web');
    Role::findOrCreate('facility_admin','web');
    $this->owner = User::factory()->create(['status'=>'active']);
    $this->owner->assignRole('provider_admin');
    $this->country = Country::create(['name'=>'Fee Country','iso2'=>'ZQ','iso3'=>'ZQA','phone_code'=>'0','region'=>'Asia','subregion'=>'Asia','status'=>1]);
    $state = State::create(['name'=>'Fee State','country_id'=>$this->country->id,'country_code'=>'ZQA']);
    $this->city = City::create(['name'=>'Fee City','country_id'=>$this->country->id,'country_code'=>'ZQA','state_id'=>$state->id,'status'=>1]);
    $this->business = Business::create(['name'=>'Fee Business','username'=>'fee-business','owner_id'=>$this->owner->id,'country_id'=>$this->country->id,'plan'=>'free','status'=>'active']);
    BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$this->owner->id,'role'=>'owner','scope'=>'all','status'=>'active']);
    $this->venue = Venue::create(['name'=>'Fee Venue','owner_user_id'=>$this->owner->id,'business_id'=>$this->business->id,'country_id'=>$this->country->id,'city_id'=>$this->city->id,'currency_override'=>'QAR','status'=>'active']);
    $this->listing = Listing::create(['name'=>'Fee Court','venue_id'=>$this->venue->id,'status'=>'active','base_price'=>100,'capacity'=>4,'slot_duration'=>60,'commission_rate'=>70,'payment_options'=>['deposit'=>['amount'=>30,'percentage'=>70],'site_deposit_percentage'=>99,'site_deposit_enabled'=>true]])->fresh('venue');
    $this->customer = User::factory()->create();
});

it('publishes only current global booking fees, preserving explicit zero',function () {
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0],['values'=>['facility'=>17,'site_free'=>0,'site_premium'=>3.25,'comeback_discount'=>88]]);
    app(MarketSettings::class)->clear();
    $r=$this->getJson('/api/business/booking-fees?business_id='.$this->business->id.'&country_id='.$this->country->id)->assertOk();
    expect($r->json('data.rates'))->toBe(['facility'=>17,'site_free'=>0,'site_premium'=>3.25]);
    expect(array_keys($r->json('data')))->toBe(['rates','sources']);
    $this->getJson('/api/admin/plan/booking-fees')->assertUnauthorized();
});

it('limits the upgrade comparison to the signed in business and its venue access',function () {
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>$this->country->id],['values'=>['site_free'=>13]]);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['site_premium'=>0]]);
    app(MarketSettings::class)->clear();
    $other=Venue::create(['name'=>'Other business private venue','owner_user_id'=>User::factory()->create()->id,'status'=>'active']);
    $r=$this->actingAs($this->owner,'sanctum')->getJson('/api/admin/plan/booking-fees?venue_id='.$other->id)->assertOk();
    $r->assertJsonCount(1,'data.venues')->assertJsonPath('data.venues.0.id',$this->venue->id)
      ->assertJsonPath('data.venues.0.rates.site_free',13)->assertJsonPath('data.venues.0.rates.site_premium',0)
      ->assertJsonPath('data.venues.0.sources.site_free','country')->assertJsonPath('data.venues.0.sources.site_premium','city');
    $staff=User::factory()->create(['status'=>'active','owner_user_id'=>$this->owner->id,'assigned_location_ids'=>[$this->venue->id]]);
    $staff->assignRole('facility_admin');
    BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$staff->id,'role'=>'facility_admin','scope'=>'selected','location_ids'=>[$this->venue->id],'status'=>'active']);
    Venue::create(['name'=>'Unassigned same business','owner_user_id'=>$this->owner->id,'business_id'=>$this->business->id,'status'=>'active']);
    $this->actingAs($staff,'sanctum')->getJson('/api/admin/plan/booking-fees')->assertOk()->assertJsonCount(1,'data.venues');
});

it('publishes enabled country and city rates with independent inheritance and explicit zero',function () {
    MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>$this->country->id],['values'=>['site_free'=>12,'comeback_discount_pct'=>75]]);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['site_premium'=>0,'site_free'=>null,'module_classes'=>false]]);
    app(MarketSettings::class)->clear();
    $this->getJson('/api/business/booking-fees/countries')->assertOk()->assertJsonCount(1,'data.countries')
        ->assertJsonPath('data.countries.0.custom_city_count',1)->assertJsonPath('data.countries.0.rates.site_free',12)
        ->assertJsonPath('data.countries.0.sources.site_free','country');
    $r=$this->getJson('/api/business/booking-fees/countries/'.$this->country->id.'/cities?custom_only=1')->assertOk()->assertJsonCount(1,'data.cities')
        ->assertJsonPath('data.cities.0.rates.site_free',12)->assertJsonPath('data.cities.0.rates.site_premium',0)
        ->assertJsonPath('data.cities.0.sources.site_premium','city')->assertJsonPath('data.cities.0.sources.facility','global');
    expect(array_keys($r->json('data.cities.0')))->toBe(['id','name','rates','sources']);
    expect(array_keys($r->json('data.cities.0.rates')))->toBe(['facility','site_free','site_premium']);
    $this->city->update(['status'=>0]);
    $this->getJson('/api/business/booking-fees/countries/'.$this->country->id.'/cities')->assertJsonCount(0,'data.cities');
    $this->getJson('/api/business/booking-fees/countries')->assertJsonPath('data.countries.0.custom_city_count',0);
    $this->country->update(['status'=>0]);
    $this->getJson('/api/business/booking-fees/countries')->assertJsonCount(0,'data.countries');
    $this->getJson('/api/business/booking-fees/countries/'.$this->country->id.'/cities')->assertNotFound();
});

it('bounds public city results, searches within a country and ignores unrelated or null settings',function () {
    for($i=0;$i<51;$i++) City::create(['name'=>sprintf('City %02d',$i),'country_id'=>$this->country->id,'country_code'=>'ZQA','state_id'=>$this->city->state_id,'status'=>1]);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['site_free'=>null,'module_classes'=>true]]);
    $url='/api/business/booking-fees/countries/'.$this->country->id.'/cities';
    $this->getJson($url)->assertOk()->assertJsonCount(50,'data.cities')->assertJsonPath('data.pagination.total',52)->assertJsonPath('data.pagination.last_page',2);
    $this->getJson($url.'?page=2')->assertJsonCount(2,'data.cities');
    $this->getJson($url.'?search=City%2002')->assertJsonCount(1,'data.cities')->assertJsonPath('data.cities.0.name','City 02');
    $this->getJson($url.'?search=%25')->assertJsonCount(0,'data.cities');
    $this->getJson($url.'?custom_only=1')->assertJsonCount(0,'data.cities');
    $this->getJson($url.'?page=-1')->assertUnprocessable();
    $this->getJson('/api/business/booking-fees/countries/99999999/cities')->assertNotFound();
});

it('matches upgrade rates to the storefront, online charge and saved booking split',function ($plan,$level,$method) {
    if($plan==='premium') {
        $this->business->update(['plan'=>'premium']);
        BusinessSubscription::create(['business_id'=>$this->business->id,'plan'=>'premium','status'=>'active','billing'=>'yearly','amount'=>588,'currency'=>'USD','current_period_end'=>now()->addYear()]);
    }
    if($level!=='global') MarketSetting::updateOrCreate(['scope'=>'country','scope_id'=>$this->country->id],['values'=>['site_free'=>12.5,'site_premium'=>7.25]]);
    if($level==='city') MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['site_free'=>8.25,'site_premium'=>0]]);
    app(MarketSettings::class)->clear();
    $expected=['global'=>['free'=>15.0,'premium'=>10.0],'country'=>['free'=>12.5,'premium'=>7.25],'city'=>['free'=>8.25,'premium'=>0.0]][$level][$plan];
    $data=$this->actingAs($this->owner,'sanctum')->getJson('/api/admin/plan/booking-fees')->assertOk()->json('data.venues.0');
    expect((float)$data['rates']['site_'.$plan])->toBe($expected)->and($data['sources']['site_'.$plan])->toBe($level);
    $catalog=new ReflectionMethod(MarketplaceCatalogService::class,'paymentOptions');
    expect((float)$catalog->invoke(app(MarketplaceCatalogService::class),$this->listing)['site_deposit_percentage'])->toBe($expected);
    $charge=new ReflectionMethod(PaymentService::class,'depositToCharge');
    expect((float)$charge->invoke(app(PaymentService::class),$this->listing,100,1,'site'))->toBe($expected);
    $form=['venue_id'=>$this->venue->id,'listing_id'=>$this->listing->id,'payment_method'=>$method,'surface'=>'site','quantity'=>1,'add_ons'=>[], 'commission_rate'=>99,'country_id'=>999,'plan'=>'free'];
    $booking=app(BookingService::class)->createSingleBooking($this->customer,$form,now()->addDay()->toDateString(),'10:00','11:00',100,0,0,null,null,null,$this->listing,new Request())->fresh();
    expect((float)$booking->commission_effective_pct)->toBe($expected)->and((int)$booking->commission_fee_minor)->toBe((int)round($expected*100))->and((int)$booking->merchant_share_minor)->toBe(10000-(int)round($expected*100));
    if($method==='deposit_remaining_on_arrival') {
        expect((float)$booking->payments()->where('type','deposit')->value('amount'))->toBe($expected)
          ->and((float)$booking->payments()->where('type','remaining')->value('amount'))->toBe(100-$expected);
    } else expect((float)$booking->payments()->where('type','full')->value('amount'))->toBe(100.0);
    MarketSetting::updateOrCreate(['scope'=>'city','scope_id'=>$this->city->id],['values'=>['site_free'=>55,'site_premium'=>44]]);
    app(MarketSettings::class)->clear();
    expect((float)$booking->fresh()->commission_effective_pct)->toBe($expected);
})->with(['free','premium'])->with(['global','country','city'])->with(['online_payment','deposit_remaining_on_arrival']);
