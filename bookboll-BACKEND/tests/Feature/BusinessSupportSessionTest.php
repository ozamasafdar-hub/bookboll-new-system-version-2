<?php

use App\Models\{AuditLog, Business, BusinessMembership, BusinessSupportSession, PersonalAccessToken, User, Venue};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Mail};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.enabled'=>true, 'preview.workspace'=>true]); Mail::fake();
    foreach (['super_admin','admin','platform_staff','provider_admin','facility_admin','customer'] as $role) Role::findOrCreate($role,'web');
});
function bsUser(string $role): User {
    $user=User::factory()->create(['status'=>'active','workspace_access'=>true]); $user->assignRole($role); return $user;
}
function bsFixture(): array {
    $admin=bsUser('super_admin'); $owner=bsUser('provider_admin');
    $business=Business::create(['name'=>'QA Support business','username'=>uniqid('qa-support-'),'owner_id'=>$owner->id,'legacy_owner_id'=>$owner->id,'status'=>'active']);
    BusinessMembership::create(['business_id'=>$business->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active','scope'=>'all']);
    $token=$admin->createToken('normal-admin');
    return [$admin,$owner,$business,$token];
}
function bsRequest(string $token, string $method, string $url, array $body=[]) {
    Auth::forgetGuards();
    return test()->withHeader('Authorization','Bearer '.$token)->json($method,$url,$body);
}
function bsStart(array $fixture): array {
    return bsRequest($fixture[3]->plainTextToken,'POST','/api/admin/businesses/'.$fixture[2]->id.'/support-session')->assertOk()->json('data');
}

it('opens a scoped business session, audits edits and exits without changing the superadmin token',function () {
    $f=bsFixture(); [$admin,$owner,$business,$original]=$f;
    $venue=Venue::forceCreate(['name'=>'Only this business','status'=>'active','business_id'=>$business->id,'owner_user_id'=>$owner->id]);
    $foreign=Venue::forceCreate(['name'=>'Other business','status'=>'active','owner_user_id'=>bsUser('provider_admin')->id]);
    $data=bsStart($f); $token=$data['token'];
    bsRequest($token,'GET','/api/me')->assertOk()->assertJsonPath('data.user.id',$owner->id)->assertJsonPath('data.scope.is_global',false)->assertJsonPath('data.support_session.actor_id',$admin->id);
    bsRequest($token,'GET','/api/admin/venues/dashboard/'.$foreign->id)->assertForbidden();
    bsRequest($token,'GET','/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.perspective','provider');
    bsRequest($token,'PUT','/api/business/brand',['brand_name'=>'QA supported brand'])->assertOk();
    $audit=AuditLog::where('action','business.support.action')->latest('id')->first();
    expect($audit->actor_id)->toBe($admin->id)->and(json_decode($audit->details,true)['effective_user_id'])->toBe($owner->id);
    bsRequest($original->plainTextToken,'GET','/api/me')->assertOk()->assertJsonPath('data.user.id',$admin->id);
    bsRequest($token,'POST','/api/business-support/exit')->assertOk();
    expect(PersonalAccessToken::find($original->accessToken->id))->not->toBeNull();
    bsRequest($token,'GET','/api/me')->assertUnauthorized();
    expect(BusinessSupportSession::first()->end_reason)->toBe('returned_to_superadmin');
});

it('refuses support starts for every non-superadmin role',function (string $role) {
    $f=bsFixture(); $u=bsUser($role); $token=$u->createToken('normal')->plainTextToken;
    bsRequest($token,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertForbidden();
})->with(['admin','platform_staff','provider_admin','facility_admin','customer']);

it('supports existing owner formats without changing their roles or enabling their login',function (string $format) {
    $f=bsFixture(); $owner=$f[1]; $owner->forceFill(['workspace_access'=>false])->save();
    if ($format==='legacy') BusinessMembership::where('user_id',$owner->id)->delete();
    else BusinessMembership::where('user_id',$owner->id)->update(['role'=>'provider_admin']);
    $data=bsStart($f);
    bsRequest($data['token'],'GET','/api/me')->assertOk()->assertJsonPath('data.user.id',$owner->id)->assertJsonPath('data.scope.is_global',false);
    expect((bool)$owner->fresh()->workspace_access)->toBeFalse();
    // An explicit revocation must win over the old businesses.owner_id link.
    BusinessMembership::updateOrCreate(['business_id'=>$f[2]->id,'user_id'=>$owner->id],['role'=>'provider_admin','status'=>'revoked','scope'=>'all']);
    bsRequest($data['token'],'GET','/api/me')->assertUnauthorized();
    bsRequest($f[3]->plainTextToken,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertUnprocessable();
})->with(['legacy','provider_admin']);

it('uses an eligible co-owner if the primary owner is inactive',function () {
    $f=bsFixture(); $f[1]->update(['status'=>'inactive']); $co=bsUser('provider_admin');
    BusinessMembership::create(['business_id'=>$f[2]->id,'user_id'=>$co->id,'role'=>'provider_admin','status'=>'active','scope'=>'all']);
    $data=bsStart($f);
    bsRequest($data['token'],'GET','/api/me')->assertOk()->assertJsonPath('data.user.id',$co->id);
});

it('never treats platform roles or ordinary business staff as support owners',function () {
    $f=bsFixture(); $f[1]->assignRole('admin');
    bsRequest($f[3]->plainTextToken,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertUnprocessable();
    $f[1]->syncRoles(['facility_admin']);
    BusinessMembership::where('user_id',$f[1]->id)->update(['role'=>'facility_admin']);
    bsRequest($f[3]->plainTextToken,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertUnprocessable();
});

it('blocks sensitive writes and legacy GET side effects even with a valid support token',function () {
    $f=bsFixture(); $token=bsStart($f)['token'];
    foreach ([['POST','/api/admin/payments/1/refund'],['PUT','/api/business/settings/account'],['POST','/api/business/team'],
        ['POST','/api/admin/businesses/'.$f[2]->id.'/support-session'],['POST','/api/admin/payment-connections/connect'],
        ['POST','/api/login'],['GET','/api/admin/plan/checkout/session'],['GET','/api/admin/notifications/sweep'],
        ['POST','/api/broadcasting/auth'],['GET','/admin'],['PUT','/api/admin/users/1'],['POST','/api/admin/bookings/dashboard-cancel'],
        ['POST','/api/admin/bookings/dashboard'],['PUT','/api/admin/bookings/1/dashboard']] as [$method,$url]) {
        bsRequest($token,$method,$url,['payment_method'=>'paid_cash','total'=>100])->assertForbidden();
    }
    expect(AuditLog::where('action','business.support.blocked')->count())->toBe(14);
});

it('ends support if its source or business access is revoked',function (string $reason) {
    $f=bsFixture(); $data=bsStart($f); $session=BusinessSupportSession::first();
    match($reason) {
        'source' => $f[3]->accessToken->delete(),
        'actor' => $f[0]->update(['status'=>'inactive']),
        'role' => $f[0]->syncRoles(['admin']),
        'business' => $f[2]->update(['status'=>'suspended']),
        'owner' => $f[1]->update(['status'=>'inactive']),
        'membership' => BusinessMembership::where('user_id',$f[1]->id)->update(['status'=>'revoked']),
        'expiry' => $session->update(['expires_at'=>now()->subSecond()]),
    };
    bsRequest($data['token'],'GET','/api/me')->assertUnauthorized();
    expect($session->fresh()->ended_at)->not->toBeNull();
})->with(['source','actor','role','business','owner','membership','expiry']);

it('requires an active owner and an enabled business and does not enable copied owner logins',function () {
    $f=bsFixture(); $f[1]->forceFill(['workspace_access'=>false])->save();
    $data=bsStart($f); bsRequest($data['token'],'GET','/api/me')->assertOk();
    expect((bool)$f[1]->fresh()->workspace_access)->toBeFalse();
    bsRequest($data['token'],'POST','/api/business-support/exit')->assertOk();
    $f[1]->update(['status'=>'inactive']);
    bsRequest($f[3]->plainTextToken,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertUnprocessable();
    $f[2]->update(['status'=>'suspended']);
    bsRequest($f[3]->plainTextToken,'POST','/api/admin/businesses/'.$f[2]->id.'/support-session')->assertUnprocessable();
});

it('rejects an orphan support token instead of treating it as an owner login',function () {
    $f=bsFixture(); $token=$f[1]->createToken('bookboll-business-support',['business-support'],now()->addMinutes(30));
    bsRequest($token->plainTextToken,'GET','/api/me')->assertUnauthorized();
});

it('creates an unpaid staff booking with real superadmin attribution and refuses another business',function () {
    $f=bsFixture(); $data=bsStart($f);
    $venue=Venue::forceCreate(['name'=>'Support QA court','status'=>'active','business_id'=>$f[2]->id,'owner_user_id'=>$f[1]->id]);
    $listing=\App\Models\Listing::create(['name'=>'Support QA listing','venue_id'=>$venue->id,'base_price'=>100,'capacity'=>4,'slot_duration'=>1,'payment_options'=>['cash_on_arrival']]);
    $body=['location_id'=>$venue->id,'listing_id'=>$listing->id,'booking_date'=>'2027-03-01','start_time'=>'10:00','end_time'=>'11:00','total'=>100,'payment_method'=>'cash'];
    bsRequest($data['token'],'POST','/api/admin/bookings/dashboard',$body)->assertCreated();
    $booking=\App\Models\Booking::latest('id')->first();
    expect($booking->created_by)->toBe($f[0]->id)->and($booking->origin)->toBe('manual_admin')
        ->and($booking->payments()->first()->status)->toBe('pending');
    bsRequest($data['token'],'PUT','/api/admin/bookings/'.$booking->id.'/dashboard',['notes'=>'QA routine edit'])->assertOk();
    $foreign=Venue::forceCreate(['name'=>'Outside support business','status'=>'active','owner_user_id'=>bsUser('provider_admin')->id]);
    $body['location_id']=$foreign->id;
    bsRequest($data['token'],'POST','/api/admin/bookings/dashboard',$body)->assertForbidden();
    bsRequest($data['token'],'PUT','/api/admin/venues/dashboard/'.$foreign->id,['name'=>'Should not save'])->assertForbidden();
    expect($foreign->fresh()->name)->toBe('Outside support business');
});

it('uses the scoped bearer even when a superadmin web cookie is also authenticated',function () {
    $f=bsFixture(); $data=bsStart($f); Auth::forgetGuards();
    $this->actingAs($f[0],'web')->withHeader('Authorization','Bearer '.$data['token'])
        ->getJson('/api/me')->assertOk()->assertJsonPath('data.user.id',$f[1]->id)->assertJsonPath('data.scope.is_global',false);
    BusinessSupportSession::first()->finish('test_exit'); Auth::forgetGuards();
    $this->actingAs($f[0],'web')->withHeader('Authorization','Bearer '.$data['token'])->getJson('/api/me')->assertUnauthorized();
});
