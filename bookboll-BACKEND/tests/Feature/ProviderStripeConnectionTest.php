<?php
use App\Models\{Business, BusinessPaymentConnection, BusinessMembership, User, Venue, Listing, Booking, Payment};
use App\Services\{ProviderStripeService, ProviderStripeGateway, BookingWalletService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.workspace'=>false,'services.stripe.secret'=>'sk_test_qa','services.stripe.connect_client_id'=>'ca_qa']);
    foreach(['provider_admin','super_admin','facility_admin'] as $r) Role::findOrCreate($r,'web');
});
function stripeBusiness() {
    $u=User::factory()->create(['status'=>User::ACTIVE]); $u->assignRole('provider_admin');
    $b=Business::create(['name'=>'QA Stripe','username'=>'qa-'.uniqid(),'owner_id'=>$u->id,'status'=>'active','plan'=>'free']);
    return [$u,$b];
}
function stripeConnection($u,$b) {
    return BusinessPaymentConnection::create(['business_id'=>$b->id,'stripe_account_id'=>'acct_'.$b->id,'connected_by'=>$u->id,'livemode'=>false,'status'=>'active','charges_enabled'=>true,'payouts_enabled'=>true,'transfers_enabled'=>true]);
}
function stripeBooking($u,$b,$fee=2000,$channel='marketplace') {
    $v=Venue::forceCreate(['name'=>'QA court','status'=>'active','business_id'=>$b->id,'owner_user_id'=>$u->id,'operator_type'=>'provider']);
    $l=Listing::create(['name'=>'QA listing','venue_id'=>$v->id,'status'=>'active','base_price'=>100,'capacity'=>10,'slot_duration'=>60,'payment_options'=>[]]);
    $booking=Booking::create(['user_id'=>$u->id,'venue_id'=>$v->id,'listing_id'=>$l->id,'date'=>now()->addDay()->toDateString(),'start_time'=>'17:00','end_time'=>'18:00','subtotal'=>100,'total_price'=>100,'currency'=>'QAR','status'=>'pending']);
    $booking->forceFill(['financial_context'=>['operator'=>'provider','business_id'=>$b->id,'channel'=>$channel,'net_fee_minor'=>$fee]])->save();
    return $booking;
}
function remoteStripe($id,$ready=true) { return ['id'=>$id,'country'=>'US','charges_enabled'=>$ready,'payouts_enabled'=>$ready,'capabilities'=>['transfers'=>$ready?'active':'pending']]; }
it('uses one destination and the sum of each frozen slot fee without a wallet credit',function () {
    [$u,$b]=stripeBusiness(); $a=stripeConnection($u,$b); $one=stripeBooking($u,$b,2000); $two=stripeBooking($u,$b,1250);
    $this->mock(ProviderStripeGateway::class)->shouldReceive('account')->once()->with($a->stripe_account_id)->andReturn(remoteStripe($a->stripe_account_id));
    $data=app(ProviderStripeService::class)->checkoutData([$one->id,$two->id],'full',200);
    expect($data)->toBe(['application_fee_amount'=>3250,'transfer_data'=>['destination'=>$a->stripe_account_id]]);
    expect($one->fresh()->financial_context)->not->toHaveKey('connect_destination'); // Only verified settlement stamps this.
});
it('keeps deposits on Bookboll and manual booking fees at zero',function () {
    [$u,$b]=stripeBusiness(); $one=stripeBooking($u,$b,2000,'admin');
    expect(app(ProviderStripeService::class)->checkoutData([$one->id],'deposit',20))->toBe([]);
    $a=stripeConnection($u,$b);
    $this->mock(ProviderStripeGateway::class)->shouldReceive('account')->andReturn(remoteStripe($a->stripe_account_id));
    expect(app(ProviderStripeService::class)->checkoutData([$one->id],'full',100)['application_fee_amount'])->toBe(0);
});
it('does not take a fee twice when collecting the remaining balance',function () {
    [$u,$b]=stripeBusiness(); $a=stripeConnection($u,$b); $one=stripeBooking($u,$b);
    Payment::create(['payment_id'=>generatePaymentId($one->id,'deposit'),'booking_id'=>$one->id,'user_id'=>$u->id,'amount'=>20,'currency'=>'QAR','type'=>'deposit','status'=>'paid','method'=>\PaymentMethod::ONLINE]);
    $this->mock(ProviderStripeGateway::class)->shouldReceive('account')->andReturn(remoteStripe($a->stripe_account_id));
    expect(app(ProviderStripeService::class)->checkoutData([$one->id],'full',80)['application_fee_amount'])->toBe(0);
});
it('refuses full online when Stripe capabilities are restricted',function () {
    [$u,$b]=stripeBusiness(); $a=stripeConnection($u,$b); $one=stripeBooking($u,$b);
    $this->mock(ProviderStripeGateway::class)->shouldReceive('account')->andReturn(remoteStripe($a->stripe_account_id,false));
    try { app(ProviderStripeService::class)->checkoutData([$one->id],'full',100); $this->fail('Restricted connection accepted'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { expect($e->getMessage())->toContain('not ready'); }
    expect($a->fresh()->status)->toBe('restricted');
    expect(app(BookingWalletService::class)->venueHasActivePayout($one->venue))->toBeFalse();
});
it('only lets the owner connect their own business and scopes the connection list',function () {
    [$u,$b]=stripeBusiness(); [$other,$otherB]=stripeBusiness(); stripeConnection($other,$otherB);
    $this->actingAs($u)->getJson('/api/admin/payment-connections')->assertOk()->assertJsonCount(1,'data.businesses')->assertJsonPath('data.businesses.0.id',$b->id);
    $this->postJson("/api/admin/payment-connections/{$otherB->id}/connect")->assertForbidden();
    $url=$this->postJson("/api/admin/payment-connections/{$b->id}/connect")->assertOk()->json('data.url');
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    expect(DB::table('business_connect_states')->where('state_hash',hash('sha256',$query['state']))->exists())->toBeTrue();
    config(['preview.workspace'=>true]);
    $this->postJson("/api/admin/payment-connections/{$b->id}/connect")->assertStatus(409);
});
it('consumes the OAuth state once and verifies account capabilities',function () {
    [$u,$b]=stripeBusiness(); $this->actingAs($u);
    $url=$this->postJson("/api/admin/payment-connections/{$b->id}/connect")->assertOk()->json('data.url'); parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $mock=$this->mock(ProviderStripeGateway::class);
    $mock->shouldReceive('exchange')->once()->with('qa-code')->andReturn(['stripe_user_id'=>'acct_qa','livemode'=>false]);
    $mock->shouldReceive('account')->once()->with('acct_qa')->andReturn(remoteStripe('acct_qa'));
    $callback='/api/provider-stripe/callback?'.http_build_query(['state'=>$query['state'],'code'=>'qa-code']);
    $this->get($callback)->assertRedirect();
    expect(BusinessPaymentConnection::first()->ready())->toBeTrue();
    $this->getJson($callback)->assertUnprocessable();
});
it('rejects a processor receipt with the wrong destination or fee',function () {
    $expected=['transfer_data'=>['destination'=>'acct_expected'],'application_fee_amount'=>2000];
    foreach([['acct_wrong',2000],['acct_expected',1900]] as [$destination,$fee]) {
        $pi=\Stripe\PaymentIntent::constructFrom(['transfer_data'=>['destination'=>$destination],'application_fee_amount'=>$fee]);
        expect(fn()=>app(ProviderStripeService::class)->verifySettlement($expected,$pi))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
});
it('refuses mixed businesses and a connection from another Stripe mode',function () {
    [$u,$b]=stripeBusiness(); [$u2,$b2]=stripeBusiness(); $one=stripeBooking($u,$b); $two=stripeBooking($u2,$b2);
    expect(fn()=>app(ProviderStripeService::class)->checkoutData([$one->id,$two->id],'full',200))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $a=stripeConnection($u,$b); $a->update(['livemode'=>true]);
    expect(app(ProviderStripeService::class)->available($one->venue))->toBeFalse();
    expect(fn()=>app(ProviderStripeService::class)->checkoutData([$one->id],'full',100))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('does not credit the legacy wallet for a verified destination charge',function () {
    [$u,$b]=stripeBusiness(); $booking=stripeBooking($u,$b);
    $booking->forceFill(['financial_context'=>array_merge($booking->financial_context,['connect_destination'=>'acct_qa'])])->save();
    $this->mock(BookingWalletService::class)->shouldNotReceive('recordBookingOnlinePayment');
    $method=new ReflectionMethod(\App\Services\StripeService::class,'creditProviderWallet');
    $method->invoke(app(\App\Services\StripeService::class),$booking,100,false);
    expect(\App\Models\ProviderWallet::count())->toBe(0);
});
