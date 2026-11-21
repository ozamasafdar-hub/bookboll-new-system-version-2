<?php

use App\Models\{Business, BusinessMembership, User, Venue, Booking, Payment};
use App\Support\AccessScope;
use App\Mail\BusinessEmailOtpEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.workspace'=>true]); Mail::fake();
    foreach (array_unique(array_merge(config('roles.staff_roles'),config('roles.provider_roles'),['customer'])) as $r) Role::findOrCreate($r,'web');
});
function lifecycleUser(string $role='super_admin'): User {
    $u=User::factory()->create(['status'=>'active','password'=>'QA-lifecycle-12345']); $u->assignRole($role); return $u;
}
function lifecycleBusiness(?User $owner=null): Business {
    return Business::create(['name'=>'QA lifecycle','username'=>'qa-'.uniqid(),'owner_id'=>$owner?->id,'status'=>'active','plan'=>'free']);
}
function lifecycleMember(Business $b, string $role='facility_admin'): User {
    $u=lifecycleUser($role);
    BusinessMembership::create(['business_id'=>$b->id,'user_id'=>$u->id,'role'=>$role,'scope'=>'all','status'=>'active']);
    return $u;
}
function lifecycleSignup(User $u) {
    test()->postJson('/api/business/email/send-otp',['email'=>$u->email])->assertOk();
    $code=null; Mail::assertSent(BusinessEmailOtpEmail::class,function ($m) use(&$code,$u){if($m->email!==$u->email)return false;$code=$m->otp;return true;});
    $proof=test()->postJson('/api/business/email/verify-otp',['email'=>$u->email,'otp'=>$code])->assertOk()->json('data.email_verification_token');
    return test()->postJson('/api/business/register',['phone'=>'+97455500001','name'=>$u->name,'business_name'=>'QA Signup','username'=>'signup-'.uniqid(),'business_types'=>['sports_facility'],'email'=>$u->email,'password'=>'QA-lifecycle-12345','password_confirmation'=>'QA-lifecycle-12345','email_verification_token'=>$proof]);
}

it('refuses business signup by an inactive account or an existing team account',function ($type) {
    $u=lifecycleUser($type==='inactive'?'customer':($type==='member'?'facility_admin':'platform_staff'));
    if($type==='inactive')$u->update(['status'=>'inactive']);
    if($type==='member')BusinessMembership::create(['business_id'=>lifecycleBusiness()->id,'user_id'=>$u->id,'role'=>'facility_admin','scope'=>'all','status'=>'active']);
    $before=Business::count(); lifecycleSignup($u)->assertUnprocessable();
    expect(Business::count())->toBe($before)->and($u->fresh()->hasRole('provider_admin'))->toBeFalse();
})->with(['inactive','member','platform']);

it('allows an existing customer to become an owner with one canonical membership',function () {
    $u=lifecycleUser('customer'); $res=lifecycleSignup($u)->assertSuccessful();$id=$res->json('data.business.id');
    expect(AccessScope::businessId($u->fresh()))->toBe($id)->and($u->fresh()->hasRole('customer'))->toBeTrue();
    expect(BusinessMembership::where('business_id',$id)->where('user_id',$u->id)->where('role','owner')->where('status','active')->count())->toBe(1);
});

it('scopes team business and venue catalogues on the server for providers',function () {
    $owner=lifecycleUser('provider_admin');$a=lifecycleBusiness($owner);$member=lifecycleMember($a);$b=lifecycleBusiness(lifecycleUser('provider_admin'));$foreign=lifecycleMember($b);$platform=lifecycleUser();
    $mine=Venue::forceCreate(['name'=>'Own venue','business_id'=>$a->id,'owner_user_id'=>$owner->id]);
    $other=Venue::forceCreate(['name'=>'Other venue','business_id'=>$b->id]);
    $this->actingAs($owner);
    foreach(['provider_staff','platform','all'] as $scope){
        $r=$this->getJson('/api/admin/users?scope='.$scope)->assertOk();$ids=collect($r->json('data.users.data'))->pluck('id')->all();
        expect($ids)->not->toContain($foreign->id)->not->toContain($platform->id);
        expect(collect($r->json('data.venues'))->pluck('id')->all())->not->toContain($other->id);
    }
    $r=$this->getJson('/api/admin/businesses')->assertOk();expect(collect($r->json('data.businesses.data'))->pluck('id')->all())->toBe([$a->id]);
    $this->getJson('/api/admin/users/required-data')->assertOk()->assertJsonMissing(['id'=>$other->id,'name'=>'Other venue']);
    $r=$this->getJson('/api/business/team')->assertOk();expect(collect($r->json('data.users'))->pluck('id')->all())->toContain($member->id);
    $this->actingAs($member)->getJson('/api/business/team')->assertOk();
});

it('does not let a venue-restricted manager widen staff access while editing',function () {
    $owner=lifecycleUser('provider_admin');$b=lifecycleBusiness($owner);$manager=lifecycleMember($b);$staff=lifecycleMember($b,'staff');
    $one=Venue::forceCreate(['name'=>'One','business_id'=>$b->id]);$two=Venue::forceCreate(['name'=>'Two','business_id'=>$b->id]);
    $manager->update(['assigned_location_ids'=>[$one->id]]);
    $this->actingAs($manager)->putJson('/api/admin/users/'.$staff->id,['assigned_location_ids'=>[$two->id]])->assertUnprocessable();
    $this->putJson('/api/admin/users/'.$staff->id,['assigned_location_ids'=>[]])->assertUnprocessable();
});

it('returns canonical membership and legacy venue pins on login and session refresh', function () {
    $b=lifecycleBusiness(lifecycleUser('provider_admin')); $member=lifecycleMember($b);
    $one=Venue::forceCreate(['name'=>'Membership venue','business_id'=>$b->id]);
    $two=Venue::forceCreate(['name'=>'Legacy venue','business_id'=>$b->id]);
    BusinessMembership::where('user_id',$member->id)->update(['scope'=>'selected','location_ids'=>json_encode([$one->id])]);
    $member->venues()->attach($two->id);
    $member->update(['custom_permissions'=>['grant'=>[], 'revoke'=>['manage_team']]]);
    $login=$this->postJson('/api/login',['email'=>$member->email,'password'=>'QA-lifecycle-12345'])->assertOk();
    $refresh=$this->actingAs($member)->getJson('/api/me')->assertOk();
    foreach ([$login,$refresh] as $response) {
        expect($response->json('data.scope.assigned_location_ids'))->toEqualCanonicalizing([$one->id,$two->id]);
        $response->assertJsonPath('data.custom_permissions.revoke',['manage_team']);
    }
});

it('refuses to move a legacy business owner or a higher ranked platform account',function () {
    $actor=lifecycleUser();$legacy=lifecycleUser('provider_admin');$source=lifecycleBusiness($legacy);$dest=lifecycleBusiness();
    $r=$this->actingAs($actor)->postJson('/api/admin/businesses/'.$dest->id.'/assignment-preview',['user_id'=>$legacy->id,'role'=>'staff','scope'=>'all'])->assertOk();
    expect($r->json('data.blockers'))->not->toBeEmpty();
    $lower=lifecycleUser('admin');$otherSuper=lifecycleUser();
    $r=$this->actingAs($lower)->postJson('/api/admin/businesses/'.$dest->id.'/assignment-preview',['user_id'=>$otherSuper->id,'role'=>'staff','scope'=>'all'])->assertOk();
    expect($r->json('data.blockers'))->not->toBeEmpty();
});

it('removes direct platform permissions as well as roles and sessions when moving staff',function () {
    $a=lifecycleUser();$b=lifecycleBusiness();$u=lifecycleUser('platform_staff');
    $p=Spatie\Permission\Models\Permission::findOrCreate('users.manage_roles','web');$u->givePermissionTo($p);$remember=$u->remember_token;
    $u->createToken('old-platform');
    $review=$this->actingAs($a)->postJson('/api/admin/businesses/'.$b->id.'/assignment-preview',['user_id'=>$u->id,'role'=>'staff','scope'=>'all'])->assertOk()->json('data');
    $this->postJson('/api/admin/businesses/'.$b->id.'/assignments',['preview_token'=>$review['preview_token'],'idempotency_key'=>'lifecycle-move'])->assertOk();
    expect($u->fresh()->permissions->count())->toBe(0)->and($u->fresh()->remember_token)->not->toBe($remember)->and($u->tokens()->count())->toBe(0);
});

it('removes employee access while preserving their customer identity and history', function () {
    $owner=lifecycleUser('provider_admin'); $b=lifecycleBusiness($owner); $staff=lifecycleMember($b,'staff');
    $staff->forceFill(['owner_user_id'=>$owner->id])->save(); $staff->assignRole('customer'); $staff->createToken('employee');
    $this->actingAs($owner)->deleteJson('/api/business/team/'.$staff->id)->assertOk();
    expect($staff->fresh())->not->toBeNull()->and($staff->fresh()->getRoleNames()->all())->toBe(['customer'])
        ->and(AccessScope::businessId($staff->fresh()))->toBeNull()->and($staff->tokens()->count())->toBe(0);
    expect(BusinessMembership::where('user_id',$staff->id)->value('status'))->toBe('revoked');
    $this->deleteJson('/api/business/team/'.$owner->id)->assertUnprocessable();
});

it('keeps venue and capability restrictions when an existing customer joins a team', function () {
    $owner=lifecycleUser('provider_admin');$b=lifecycleBusiness($owner);$customer=lifecycleUser('customer');$hash=$customer->password;
    $one=Venue::forceCreate(['name'=>'One','business_id'=>$b->id]);$two=Venue::forceCreate(['name'=>'Two','business_id'=>$b->id]);
    $this->actingAs($owner)->postJson('/api/business/team',['name'=>'QA existing customer','email'=>$customer->email,'role'=>'staff','assigned_location_ids'=>[$one->id],'custom_permissions'=>['grant'=>[],'revoke'=>['accept_payments']]])->assertOk();
    expect(AccessScope::allowedVenueIds($customer->fresh()))->toBe([$one->id])
        ->and($customer->fresh()->hasCapability('accept_payments'))->toBeFalse()->and($customer->fresh()->password)->toBe($hash);
});

it('enforces each provider role on team and booking actions', function ($role,$teamAccess,$bookingWrite) {
    $owner=lifecycleUser('provider_admin'); $b=lifecycleBusiness($owner); $u=lifecycleMember($b,$role);
    $this->actingAs($u)->getJson('/api/business/team')->assertStatus($teamAccess?200:403);
    $this->postJson('/api/business/team',['name'=>'QA child','email'=>$role.'@example.test','role'=>'staff'])->assertStatus($teamAccess?200:403);
    $this->postJson('/api/admin/users',['name'=>'Elevation','email'=>'elevation@example.test','role'=>'super_admin','status'=>'active'])->assertForbidden();
    expect($u->hasCapability('manage_bookings'))->toBe($bookingWrite)->and($u->hasCapability('manage_payouts'))->toBeFalse();
})->with([
    ['facility_admin',true,true],['provider_supervisor',false,true],['staff',false,true],['provider_viewer',false,false],
]);

it('transfers owned venues and reconciles receipts into businesses from both creation paths', function ($path) {
    $actor=lifecycleUser();
    if($path==='self_signup') {
        $owner=lifecycleUser('customer'); $id=lifecycleSignup($owner)->assertSuccessful()->json('data.business.id');
    } else {
        $id=$this->actingAs($actor)->postJson('/api/admin/businesses', ['phone'=>'+97455500001',
            'business_name'=>'QA admin business','username'=>'qa-admin-lifecycle','business_types'=>['sports_facility'],
            'publish_website'=>false,'idempotency_key'=>'qa-admin-lifecycle',
        ])->assertOk()->json('data.business.id');
        $ownerId=$this->postJson("/api/admin/businesses/$id/invitations",['name'=>'QA owner','email'=>'qa-owner@example.test','role'=>'provider_admin'])->assertOk()->json('data.user_id');
        $owner=User::findOrFail($ownerId);
    }
    $b=Business::findOrFail($id); $b->update(['status'=>'active']);
    $v=Venue::forceCreate(['name'=>'QA private venue','operator_type'=>'bookboll','status'=>'active','currency_override'=>'QAR']);
    auth()->forgetGuards();
    foreach ([['bookboll_web','deposit',20,'paid','online'],['manual_admin','full',30,'paid','cash'],['manual_admin','full',70,'pending','cash']] as [$origin,$type,$amount,$status,$method]) {
        $booking=Booking::create(['user_id'=>$owner->id,'venue_id'=>$v->id,'date'=>'2026-11-10','start_time'=>'10:00','end_time'=>'11:00','subtotal'=>$type==='deposit'?100:$amount,'total_price'=>$type==='deposit'?100:$amount,'status'=>'completed','origin'=>$origin,'payment_status'=>$type==='deposit'?\BookingPaymentStatus::DEPOSIT_PAID:($status==='paid'?\BookingPaymentStatus::FULLY_PAID:\BookingPaymentStatus::PENDING)]);
        // Historical evidence is independent of the admin who prepared this fixture.
        $booking->forceFill(['origin'=>$origin,'created_by'=>null,'creation_context'=>['channel'=>$origin==='bookboll_web'?'marketplace':'admin'], 'financial_context'=>null,'commission_fee_minor'=>null,'commission_effective_pct'=>null,'payment_breakdown'=>null])->saveQuietly();
        Payment::create(['booking_id'=>$booking->id,'user_id'=>$owner->id,'payment_id'=>'qa-'.uniqid(),'type'=>$type,'method'=>$method,'status'=>$status,'amount'=>$amount,'currency'=>'QAR']);
        if($type==='deposit') Payment::create(['booking_id'=>$booking->id,'user_id'=>$owner->id,'payment_id'=>'qa-'.uniqid(),'type'=>'remaining','method'=>'cash','status'=>'pending','amount'=>80,'currency'=>'QAR']);
    }
    $preview=$this->actingAs($actor)->postJson("/api/admin/venues/{$v->id}/transfer-preview",[
        'business_id'=>$id,'effective_from'=>'2026-11-17','history_mode'=>'legacy_deposit_fee','arrival_policy'=>'keep_pending','from_date'=>'2026-11-01','to_date'=>'2026-11-16',
    ])->assertOk()->json('data');
    expect($preview['blockers'])->toBeEmpty();
    $this->postJson("/api/admin/venues/{$v->id}/transfers",['preview_token'=>$preview['preview_token'],'idempotency_key'=>'qa-transfer-'.$path])->assertOk();
    expect($v->fresh()->business_id)->toBe($id)->and(AccessScope::allowedVenueIds($owner->fresh()))->toContain($v->id);
    $report=$this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->json('data.summary.0');
    foreach(['platform_fees'=>20,'bookboll_received'=>20,'provider_received'=>30,'provider_pending'=>150] as $key=>$value) expect((float)$report[$key])->toBe((float)$value);
    $this->actingAs($owner->fresh())->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.perspective','provider')->assertJsonPath('data.summary.0.provider_pending',150);
    $other=lifecycleUser('provider_admin'); lifecycleBusiness($other);
    $this->actingAs($other)->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.summary',[]);
})->with(['self_signup','superadmin_invite']);

