<?php

use App\Models\{Booking, Business, Listing, Payment, User, Venue};
use App\Support\{Capabilities, PaymentReportRules};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\{Permission, Role};
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin','provider_admin','staff'] as $name) {
        $role=Role::findOrCreate($name,'web'); $caps=Capabilities::roleMap()[$name];
        foreach($caps as $cap) Permission::findOrCreate($cap,'web');
        $role->syncPermissions($caps);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->admin=User::factory()->create(['status'=>User::ACTIVE]); $this->admin->assignRole('super_admin');
    $this->customer=User::factory()->create();
    $this->venue=Venue::create(['name'=>'Booking report venue','currency_override'=>'QAR','status'=>'active']);
    $this->actingAs($this->admin);
});

function activityBooking($test,array $over=[]): Booking {
    return Booking::create(array_merge(['venue_id'=>$test->venue->id,'user_id'=>$test->customer->id,'date'=>'2026-11-10',
        'start_time'=>'18:00','end_time'=>'19:00','subtotal'=>100,'total_price'=>100,'currency'=>'QAR',
        'status'=>'confirmed','payment_status'=>'fully_paid','origin'=>'bookboll_web'],$over));
}
function activityReport($test,array $filters=[]) {
    return $test->getJson('/api/admin/bookings/dashboard?'.http_build_query(array_merge(['with_activity'=>1],$filters)));
}

it('uses the same records for table, charts and analytics and never multiplies a booking by its receipts',function(){
    $b=activityBooking($this);
    foreach([20,80] as $amount) Payment::create(['user_id'=>$b->user_id,'payment_id'=>'QA-'.Str::uuid(),'booking_id'=>$b->id,'amount'=>$amount,'currency'=>'QAR','status'=>'paid','method'=>'online','type'=>'full']);
    activityBooking($this,['status'=>'pending','payment_status'=>'pending']);
    activityBooking($this,['status'=>'cancelled']);
    activityBooking($this,['payment_method'=>'payment_skipped','payment_status'=>'payment_skipped']);
    activityBooking($this,['origin'=>'class_program']);
    $rows=activityReport($this,['per_page'=>1])->assertOk()->json('data');
    $analytics=activityReport($this,['analytics_only'=>1])->assertOk()->json('data');
    expect($rows['pagination']['total'])->toBe(4)->and($rows['activity'])->toBe($analytics['activity']);
    expect($rows['activity']['active'])->toBe(2)->and($rows['activity']['sales'][0]['amount'])->toEqual(200)
        ->and($rows['activity']['slot_blocks'])->toBe(1)->and($rows['activity']['per_customer'])->toEqual(2);
    expect(array_sum(array_column($rows['activity']['series'],'value')))->toBe(2)
        ->and(array_sum(array_column($rows['activity']['channels'],'value')))->toBe(4);
});

it('uses saved creator channels identically to payments even when legacy origin says marketplace',function(){
    $manual=activityBooking($this);
    $manual->forceFill(['creation_context'=>['channel'=>'admin','actor_name'=>'QA desk','actor_kind'=>'staff']])->saveQuietly();
    activityBooking($this,['commission_channel'=>'site']);
    activityBooking($this,['origin'=>'unknown']);
    $rows=activityReport($this)->assertOk()->json('data.bookings');
    foreach($rows as $row) expect($row['report_channel'])->toBe(PaymentReportRules::channel(Booking::find($row['id'])->toArray()));
    foreach(['manual','provider_site','unknown'] as $channel) expect(activityReport($this,['channel'=>$channel])->assertOk()->json('data.pagination.total'))->toBe(1);
    expect(activityReport($this,['search'=>'QA desk'])->assertOk()->json('data.bookings.0.id'))->toBe((string)$manual->id);
});

it('keeps saved currencies and sorts related fields without ambiguous SQL columns',function(){
    activityBooking($this,['currency'=>'PKR']);
    activityBooking($this,['currency'=>'QAR']);
    foreach(['customer','listing','created_at','booking_date','booking_status','payment_status','total'] as $sort) {
        $r=activityReport($this,['sort'=>$sort,'status'=>'confirmed','currency'=>'PKR'])->assertOk()->json('data');
        expect($r['pagination']['total'])->toBe(1)->and($r['bookings'][0]['currency'])->toBe('PKR')->and($r['activity']['sales'][0]['currency'])->toBe('PKR');
    }
});

it('distinguishes reservation date from date booked and rejects invalid date ranges',function(){
    activityBooking($this,['date'=>'2026-11-10'])->forceFill(['created_at'=>'2026-10-01 12:00:00'])->saveQuietly();
    expect(activityReport($this,['date_from'=>'2026-10-01','date_to'=>'2026-10-01'])->assertOk()->json('data.activity.matched'))->toBe(0);
    expect(activityReport($this,['date_from'=>'2026-10-01','date_to'=>'2026-10-01','date_basis'=>'created'])->assertOk()->json('data.activity.matched'))->toBe(1);
    activityReport($this,['date_from'=>'2026-11-10','date_to'=>'2026-11-01'])->assertStatus(422);
    activityReport($this,['date_from'=>'2026-02-30'])->assertStatus(422);
    activityReport($this,['date_to'=>'2026-11-10'])->assertOk();
});

it('excludes walk-in bookings from both sides of the customer frequency ratio',function(){
    activityBooking($this); activityBooking($this); activityBooking($this,['user_id'=>User::factory()->create()->id]);
    activityBooking($this,['user_id'=>User::factory()->create(['email'=>'walkin@bookboll.local'])->id]);
    $r=activityReport($this)->assertOk()->json('data.activity');
    expect($r['active'])->toBe(4)->and($r['identified_bookings'])->toBe(3)->and($r['per_customer'])->toEqual(1.5)
        ->and($r['repeat_pct'])->toEqual(50)->and($r['walk_in_bookings'])->toBe(1);
    $empty=activityReport($this,['search'=>'definitely absent'])->assertOk()->json('data.activity');
    expect($empty['per_customer'])->toBeNull()->and($empty['series'])->toBe([]);
});

it('has no 5000-row chart cap or 400-day timeline cutoff and exports cursor batches',function(){
    $rows=[];
    for($i=0;$i<5201;$i++) $rows[]=['venue_id'=>$this->venue->id,'user_id'=>$this->customer->id,'uuid'=>(string)Str::uuid(),'booking_id'=>'QA-'.$i,
        'date'=>$i===5200 ? '2027-12-01' : '2026-01-01','start_time'=>'18:00','end_time'=>'19:00','duration'=>1,'subtotal'=>10,'total_price'=>10,
        'currency'=>'QAR','status'=>'confirmed','payment_status'=>'fully_paid','created_at'=>now(),'updated_at'=>now()];
    foreach(array_chunk($rows,500) as $batch) Booking::insert($batch);
    $r=activityReport($this,['analytics_only'=>1])->assertOk()->json('data.activity');
    expect($r['active'])->toBe(5201)->and(array_sum(array_column($r['series'],'value')))->toBe(5201)
        ->and(end($r['series'])['label'])->toBe('2027-12');
    $after=0; $ids=[];
    do { $data=activityReport($this,['export'=>1,'with_activity'=>0,'after_id'=>$after])->assertOk()->json('data');
        $ids=array_merge($ids,array_column($data['bookings'],'id')); $after=$data['next_after_id'];
    } while($after);
    expect(count($ids))->toBe(5201)->and(count(array_unique($ids)))->toBe(5201);
});

it('applies business ownership and listing pins equally to charts, rows, options and booking details',function(){
    $provider=User::factory()->create(['status'=>User::ACTIVE]); $provider->assignRole('staff');
    $business=Business::create(['name'=>'Ownerless QA','username'=>'ownerless-qa','status'=>'active']);
    \App\Models\BusinessMembership::create(['business_id'=>$business->id,'user_id'=>$provider->id,'role'=>'staff','scope'=>'all','status'=>'active','version'=>1]);
    $this->venue->forceFill(['business_id'=>$business->id,'operator_type'=>'provider'])->saveQuietly();
    $court=Listing::create(['name'=>'Allowed court','venue_id'=>$this->venue->id,'base_price'=>100,'capacity'=>4,'slot_duration'=>1,'payment_options'=>['cash_on_arrival']]);
    $other=Listing::create(['name'=>'Hidden court','venue_id'=>$this->venue->id,'base_price'=>100,'capacity'=>4,'slot_duration'=>1,'payment_options'=>['cash_on_arrival']]);
    $provider->forceFill(['assigned_listing_ids'=>[$court->id]])->saveQuietly();
    $allowed=activityBooking($this,['listing_id'=>$court->id]);
    $hidden=activityBooking($this,['listing_id'=>$other->id]);
    $outside=Venue::create(['name'=>'Outside venue']); activityBooking($this,['venue_id'=>$outside->id]);
    $this->actingAs($provider->fresh());
    $r=activityReport($this)->assertOk()->json('data');
    expect($r['activity']['matched'])->toBe(1)->and($r['pagination']['total'])->toBe(1)
        ->and(array_column($r['listings'],'id'))->toBe([(string)$court->id]);
    $this->getJson('/api/admin/bookings/'.$hidden->id.'/crm')->assertForbidden();
    activityReport($this,['export'=>1,'with_activity'=>0])->assertForbidden();
    $prior=activityBooking($this,['listing_id'=>$court->id]);
    $prior->forceFill(['financial_context'=>['operator'=>'bookboll']])->saveQuietly();
    expect(activityReport($this)->assertOk()->json('data.activity.matched'))->toBe(1);
    $this->getJson('/api/admin/bookings/'.$prior->id.'/crm')->assertForbidden();
    $this->actingAs($this->admin);
    expect(activityReport($this,['business_id'=>(string)$business->id])->assertOk()->json('data.pagination.total'))->toBe(3);
    expect(activityReport($this,['business_id'=>'bookboll'])->assertOk()->json('data.pagination.total'))->toBe(1);
});
