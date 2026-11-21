<?php

use App\Models\{Business, BusinessMembership, User, Venue, Booking, Payment};
use App\Support\{AccessScope, BusinessMembershipSync};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.workspace'=>true]); Mail::fake();
    foreach (array_unique(array_merge(config('roles.staff_roles'),config('roles.provider_roles'),['customer'])) as $r) Role::findOrCreate($r,'web');
});
function moUser(string $role='super_admin'): User {
    $u=User::factory()->create(['status'=>'active','password'=>'QA-multi-owner-12345']); $u->assignRole($role); return $u;
}
function moBusiness(): array {
    $owner=moUser('provider_admin');
    $b=Business::create(['name'=>'QA shared business','username'=>'co-'.uniqid(),'owner_id'=>$owner->id,'legacy_owner_id'=>$owner->id,'status'=>'active','phone'=>'+97455500001']);
    BusinessMembership::create(['business_id'=>$b->id,'user_id'=>$owner->id,'role'=>'owner','scope'=>'all','status'=>'active','version'=>1]);
    return [$b,$owner];
}
function moInvite(User $actor, Business $b, bool $provider=false): User {
    $r=test()->actingAs($actor)->postJson($provider?'/api/business/team':"/api/admin/businesses/{$b->id}/invitations",['name'=>'QA co-owner','email'=>uniqid('co-').'@example.test','role'=>'provider_admin','status'=>'active']);
    $r->assertOk(); return User::findOrFail($r->json('data.user_id'));
}

it('allows superadmin and owners to invite co-owners into the same workspace',function (bool $provider) {
    [$b,$owner]=moBusiness(); $actor=$provider?$owner:moUser();
    $co=moInvite($actor,$b,$provider);
    expect(AccessScope::managedBusiness($co)->id)->toBe($b->id)->and(AccessScope::ownerUserId($co))->toBe($owner->id)
        ->and($b->fresh()->owner_id)->toBe($owner->id)->and(Business::count())->toBe(1);
    $venue=Venue::forceCreate(['name'=>'Shared court','status'=>'active','business_id'=>$b->id,'owner_user_id'=>$owner->id]);
    foreach ([$owner,$co] as $u) {
        expect(AccessScope::allowedVenueIds($u))->toContain($venue->id);
        $this->actingAs($u)->getJson('/api/business/team')->assertOk()->assertJsonCount(2,'data.users');
        $this->getJson('/api/business/settings')->assertOk();
        $this->putJson('/api/business/brand',['brand_name'=>'Same business'])->assertOk();
        $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk()->assertJsonPath('data.perspective','provider');
    }
    $admin=moUser(); $row=$this->actingAs($admin)->getJson('/api/admin/businesses')->assertOk()->json('data.businesses.data.0');
    expect($row['owner_count'])->toBe(2)->and($row['active_owner_count'])->toBe(2)->and($row['owner_user_id'])->toBe($owner->id);
})->with([true,false]);

it('adds owners to a superadmin-created business without replacing the first owner',function () {
    $admin=moUser(); $id=$this->actingAs($admin)->postJson('/api/admin/businesses',['business_name'=>'QA admin business','phone'=>'+97455500001','username'=>'qa-owner-admin','business_types'=>['sports_facility'],'idempotency_key'=>'mo-create'])->assertOk()->json('data.business.id');
    // Creation responses expose the business directly in current versions.
    $b=$id?Business::findOrFail($id):Business::where('username','qa-owner-admin')->firstOrFail();
    $one=moInvite($admin,$b); $two=moInvite($one,$b->fresh(),true);
    expect($b->fresh()->owner_id)->toBe($one->id)->and($b->fresh()->legacy_owner_id)->toBe($one->id)->and(AccessScope::businessId($two))->toBe($b->id);
});

it('lets a self-signup customer owner add a co-owner and retain shared scope after an ordinary edit',function () {
    $u=moUser('customer');
    $this->postJson('/api/business/email/send-otp',['email'=>$u->email])->assertOk();
    $code=null; Mail::assertSent(\App\Mail\BusinessEmailOtpEmail::class,function ($m) use(&$code){$code=$m->otp;return true;});
    $proof=$this->postJson('/api/business/email/verify-otp',['email'=>$u->email,'otp'=>$code])->assertOk()->json('data.email_verification_token');
    $id=$this->postJson('/api/business/register',['phone'=>'+97455500001','name'=>$u->name,'business_name'=>'QA Signup','username'=>'signup-'.uniqid(),'business_types'=>['sports_facility'],'email'=>$u->email,'password'=>'QA-multi-owner-12345','password_confirmation'=>'QA-multi-owner-12345','email_verification_token'=>$proof])->assertSuccessful()->json('data.business.id');
    $b=Business::findOrFail($id);$co=moInvite($u->fresh(),$b,true);
    $this->actingAs($co)->putJson('/api/business/team/'.$u->id,['name'=>'Updated primary owner'])->assertOk();
    expect($u->fresh()->activeBusinessMembership->role)->toBe('owner')->and(AccessScope::managedBusiness($u->fresh())->id)->toBe($id);
});

it('allows another owner to reactivate a disabled co-owner without changing the primary contact',function () {
    [$b,$owner]=moBusiness(); $co=moInvite($owner,$b,true);
    $this->actingAs($owner)->putJson('/api/business/team/'.$co->id,['status'=>'inactive'])->assertOk();
    $this->putJson('/api/business/team/'.$co->id,['status'=>'active'])->assertOk();
    expect($co->fresh()->status)->toBe('active')->and($b->fresh()->owner_id)->toBe($owner->id);
});

it('promotes and demotes members through either dashboard and revokes old sessions',function (bool $provider) {
    [$b,$owner]=moBusiness(); $admin=moUser(); $co=moInvite($owner,$b,true);
    $staff=moUser('facility_admin'); BusinessMembership::create(['business_id'=>$b->id,'user_id'=>$staff->id,'role'=>'facility_admin','scope'=>'all','status'=>'active']);
    $token=$staff->createToken('old');
    $base=$provider?'/api/business/team/':'/api/admin/users/'; $actor=$provider?$owner:$admin;
    $this->actingAs($actor)->putJson($base.$staff->id,['role'=>'provider_admin'])->assertOk();
    expect($staff->fresh()->hasRole('provider_admin'))->toBeTrue()->and($staff->tokens()->count())->toBe(0)
        ->and(AccessScope::managedBusiness($staff->fresh())->id)->toBe($b->id);
    $this->putJson($base.$co->id,['role'=>'staff'])->assertOk();
    expect($co->fresh()->hasRole('staff'))->toBeTrue()->and(AccessScope::managedBusiness($co->fresh()))->toBeNull()
        ->and($co->activeBusinessMembership()->first()->role)->toBe('staff');
})->with([true,false]);

it('preserves the data key and shared settings when the original primary owner leaves',function (string $action) {
    [$b,$owner]=moBusiness(); $co=moInvite($owner,$b,true); $original=$b->owner_id;
    $venue=Venue::forceCreate(['name'=>'Legacy court','status'=>'active','owner_user_id'=>$original]);
    $booking=Booking::create(['user_id'=>$co->id,'venue_id'=>$venue->id,'date'=>'2026-11-21','start_time'=>'10:00','end_time'=>'11:00','subtotal'=>100,'total_price'=>100,'status'=>'completed','origin'=>'admin','payment_status'=>BookingPaymentStatus::FULLY_PAID]);
    $payment=Payment::create(['booking_id'=>$booking->id,'user_id'=>$co->id,'payment_id'=>'mo-paid','type'=>'full','method'=>'cash','status'=>'paid','amount'=>100,'currency'=>'QAR']);
    $before=$payment->fresh()->getAttributes(); $owner->createToken('old-owner');
    $this->actingAs($co);
    if ($action==='remove') $this->deleteJson('/api/business/team/'.$owner->id)->assertOk();
    else $this->putJson('/api/business/team/'.$owner->id,$action==='demote'?['role'=>'staff']:['status'=>'inactive'])->assertOk();
    expect($b->fresh()->owner_id)->toBe($co->id)->and($b->fresh()->legacy_owner_id)->toBe($original)
        ->and(AccessScope::ownerUserId($co))->toBe($original)->and(Business::forOwnerId($original)->id)->toBe($b->id)
        ->and(AccessScope::allowedVenueIds($co))->toContain($venue->id)->and($payment->fresh()->getAttributes())->toBe($before)
        ->and($owner->tokens()->count())->toBe(0);
    $this->getJson('/api/business/team')->assertOk();
    expect(app(\App\Services\WhatsappService::class)->ownerContact($original)['email'])->toBe($co->email);
    expect(\App\Support\PaymentFinancialContext::forNewBooking($booking)['business_id'])->toBe($b->id);
    if ($action==='remove') {
        expect(AccessScope::businessId($owner->fresh()))->toBeNull()->and(AccessScope::managedBusiness($owner->fresh()))->toBeNull();
        $this->actingAs($owner->fresh())->getJson('/api/business/team')->assertForbidden();
    }
})->with(['remove','demote','deactivate']);

it('blocks removal demotion and deactivation of the last active owner',function (string $action) {
    [$b,$owner]=moBusiness(); $admin=moUser(); $co=moInvite($admin,$b);
    $co->update(['status'=>'inactive']); $this->actingAs($admin);
    $r=$action==='remove'?$this->deleteJson('/api/admin/users/'.$owner->id):$this->putJson('/api/admin/users/'.$owner->id,$action==='demote'?['role'=>'staff']:['status'=>'inactive']);
    $r->assertUnprocessable();
    expect($owner->fresh()->status)->toBe('active')->and($owner->fresh()->hasRole('provider_admin'))->toBeTrue()->and($b->fresh()->owner_id)->toBe($owner->id);
})->with(['remove','demote','deactivate']);

it('rejects ownership escalation by managers staff and non-superadmin platform accounts',function (string $role) {
    [$b,$owner]=moBusiness(); $actor=moUser($role);
    if (in_array($role,['facility_admin','staff'])) BusinessMembership::create(['business_id'=>$b->id,'user_id'=>$actor->id,'role'=>$role,'scope'=>'all','status'=>'active']);
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/invitations",['name'=>'Denied','email'=>'denied@example.test','role'=>'provider_admin'])->assertStatus(in_array($role,['admin','facility_admin'])?422:403);
    $this->putJson('/api/admin/users/'.$actor->id,['role'=>'provider_admin'])->assertForbidden();
    $this->putJson('/api/admin/users/'.$owner->id,['status'=>'inactive'])->assertForbidden();
})->with(['facility_admin','staff','admin','country_admin']);

it('keeps owners scoped to their own business and prohibits direct creation of another business',function () {
    [$b,$owner]=moBusiness(); [$other,$foreign]=moBusiness(); $co=moInvite($owner,$b,true);
    $this->actingAs($co)->postJson("/api/admin/businesses/{$other->id}/invitations",['name'=>'No','email'=>'no@example.test','role'=>'provider_admin'])->assertForbidden();
    $this->putJson('/api/admin/users/'.$foreign->id,['role'=>'staff'])->assertForbidden();
    $this->putJson('/api/business/team/'.$foreign->id,['role'=>'provider_admin'])->assertNotFound();
    $this->postJson('/api/admin/users',['name'=>'No new business','email'=>'none@example.test','role'=>'provider_admin','status'=>'active'])->assertUnprocessable();
    expect(Business::count())->toBe(2);
});

it('rejects restricted or inactive owner invitations and protects self access',function () {
    [$b,$owner]=moBusiness(); $this->actingAs($owner);
    foreach ([['status'=>'inactive'],['permissions'=>['grant'=>[],'revoke'=>['manage_team']]]] as $extra) {
        $this->postJson('/api/business/team',array_merge(['name'=>'Invalid owner','email'=>uniqid().'@example.test','role'=>'provider_admin'],$extra))->assertUnprocessable();
    }
    $this->putJson('/api/business/team/'.$owner->id,['role'=>'staff'])->assertUnprocessable();
});

it('requires team management for changing an owner status through customer APIs',function () {
    [$b,$owner]=moBusiness(); $owner->assignRole('customer');
    $this->actingAs(moUser())->putJson('/api/admin/customers/'.$owner->id.'/status',['status'=>'blocked'])->assertUnprocessable();
    expect($owner->fresh()->status)->toBe('active');
});

it('has removed the MySQL one-owner index and refuses a lossy rollback',function () {
    [$b,$owner]=moBusiness(); moInvite($owner,$b,true);
    expect(collect(\Illuminate\Support\Facades\Schema::getIndexes('business_memberships'))->contains('name','business_memberships_one_active_owner'))->toBeFalse();
    $migration=require database_path('migrations/2026_11_21_000001_allow_multiple_business_owners.php');
    expect(fn()=>$migration->down())->toThrow(RuntimeException::class);
});

it('lists a business once in provider filters and analytics after its primary owner leaves',function () {
    [$b,$owner]=moBusiness(); $co=moInvite($owner,$b,true); $admin=moUser();
    foreach ([false,true] as $remove) {
        if ($remove) $this->actingAs($co)->deleteJson('/api/business/team/'.$owner->id)->assertOk();
        foreach ([$admin,$co] as $actor) {
            foreach (['/api/admin/reference-data','/api/admin/dashboard/analytics'] as $path) {
                $providers=$this->actingAs($actor)->getJson($path)->assertOk()->json('data.providers');
                expect($providers)->toHaveCount(1)->and($providers[0]['id'])->toBe((string)$owner->id)
                    ->and($providers[0]['business_name'])->toBe($b->name);
            }
        }
    }
});
