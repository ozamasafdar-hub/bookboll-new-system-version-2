<?php
use App\Models\{Booking,Preference,Promocode,User,Venue,MarketSetting};
use App\Services\ComebackOfferService;
use App\Jobs\DeliverComebackOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Notification,Http};
uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(now()->setDate(2026,11,13)->setTime(12,0));
    config(['preview.enabled'=>true]); Notification::fake(); Http::preventStrayRequests();
    $this->user=User::factory()->create(['phone'=>'+97455555555']);
    Preference::create(['user_id'=>$this->user->id,'notifications'=>['marketing_emails'=>true,'marketing_whatsapp'=>true]]);
    $this->venue=Venue::create(['name'=>'QA Pitch','status'=>'active','timezone_override'=>'Asia/Qatar']);
});
function comebackBooking(array $extra=[]): Booking {
    $b=Booking::create(array_merge(['user_id'=>test()->user->id,'created_by'=>test()->user->id,'venue_id'=>test()->venue->id,'date'=>now()->toDateString(),'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,'status'=>'confirmed','origin'=>'bookboll_web','payment_method'=>'deposit_remaining_on_arrival','payment_status'=>'deposit_paid'],$extra));
    $b->forceFill(['creation_context'=>['channel'=>$extra['channel']??'marketplace']])->save(); return $b->fresh();
}
it('issues one venue/account bound offer after the session and never sends in preview',function(){
    $b=comebackBooking(); $this->artisan('send-booking-reminders')->assertExitCode(0); $this->artisan('send-booking-reminders')->assertExitCode(0);
    $p=Promocode::sole(); expect($p->discount_value)->toEqual(10)->and($p->ends_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:00');
    expect($p->isValidForOrder(100,$this->user->id,'deposit_remaining_on_arrival',$this->venue->id))->toBeTrue();
    expect($p->isValidForOrder(100,User::factory()->create()->id,'online_payment',$this->venue->id))->toBeFalse();
    expect($p->isValidForOrder(100,$this->user->id,'online_payment',Venue::create(['name'=>'Other'])->id))->toBeFalse();
    expect(DB::table('booking_comeback_offers')->sole()->email_status)->toBe('preview');
    Notification::assertNothingSent(); Http::assertNothingSent();
});
it('waits until the last adjacent slot ends',function(){
    comebackBooking(); comebackBooking(['start_time'=>'11:00','end_time'=>'13:00']);
    $this->artisan('send-booking-reminders')->assertExitCode(0); expect(Promocode::count())->toBe(0);
    $this->travelTo(now()->setTime(13,1)); $this->artisan('send-booking-reminders'); expect(Promocode::count())->toBe(1);
});
it('does not send for staff website skipped cancelled or old bookings',function($extra){
    comebackBooking($extra); $this->artisan('send-booking-reminders'); expect(Promocode::count())->toBe(0);
})->with([[['channel'=>'admin']],[['channel'=>'site']],[['payment_method'=>'payment_skipped']],[['status'=>'cancelled']],[['date'=>'2026-10-01']]]);
it('does not turn a repeat visitor into a first visit',function(){
    comebackBooking(['date'=>'2026-10-01']); comebackBooking(); $this->artisan('send-booking-reminders'); expect(Promocode::count())->toBe(0);
});
it('honors disabling and consent without later issuing a retroactive offer',function(){
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0],['values'=>['comeback_enabled'=>false]]);
    comebackBooking(); $this->artisan('send-booking-reminders'); expect(Promocode::count())->toBe(0)->and(DB::table('booking_comeback_offers')->sole()->status)->toBe('skipped');
});
it('honors each channel consent and uses the configured rate',function(){
    $this->user->preferences->update(['notifications'=>['marketing_emails'=>false,'marketing_whatsapp'=>true]]);
    MarketSetting::updateOrCreate(['scope'=>'global','scope_id'=>0],['values'=>['comeback_discount_pct'=>7]]);
    comebackBooking(); $this->artisan('send-booking-reminders'); expect(Promocode::sole()->discount_value)->toEqual(7);
    expect(DB::table('booking_comeback_offers')->sole()->email_status)->toBe('skipped')->and(DB::table('booking_comeback_offers')->sole()->whatsapp_status)->toBe('preview');
});
it('expires at precisely seven days after session end',function(){
    comebackBooking(); $this->artisan('send-booking-reminders'); $p=Promocode::sole(); $this->travelTo($p->ends_at);
    expect($p->isValidForOrder(100,$this->user->id,'online_payment',$this->venue->id))->toBeFalse();
});
it('delivers each opted in channel once using the actual venue link',function(){
    config(['preview.enabled'=>false,'services.whatsapp.comeback_template'=>'qa_comeback']);
    $wa=Mockery::mock(\App\Services\WhatsappService::class);
    $wa->shouldReceive('platformSender')->once()->andReturn(['phone_number_id'=>'test','token'=>'test']);
    $wa->shouldReceive('sendTemplate')->once()->withArgs(function($sender,$message){
        return $message['variables'][5]===rtrim(config('app.frontend_url'),'/').'/facilities/'.test()->venue->id;
    })->andReturn(['ok'=>true]);app()->instance(\App\Services\WhatsappService::class,$wa);
    $b=comebackBooking(); app(ComebackOfferService::class)->consider($b);
    $id=DB::table('booking_comeback_offers')->sole()->id;
    (new DeliverComebackOffer($id))->handle();(new DeliverComebackOffer($id))->handle();
    Notification::assertSentToTimes($this->user,\App\Notifications\BookingReminderNotification::class,1);
    expect(DB::table('booking_comeback_offers')->sole()->whatsapp_status)->toBe('sent');
    Http::assertNothingSent();
});
it('rechecks withdrawn consent before sending',function(){
    config(['preview.enabled'=>false]); $b=comebackBooking();app(ComebackOfferService::class)->consider($b);
    $this->user->preferences->update(['notifications'=>['marketing_emails'=>false,'marketing_whatsapp'=>false]]);
    (new DeliverComebackOffer(DB::table('booking_comeback_offers')->sole()->id))->handle();
    Notification::assertNothingSent();Http::assertNothingSent();
    expect(DB::table('booking_comeback_offers')->sole()->email_status)->toBe('skipped');
});
