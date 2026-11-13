<?php
use App\Models\{Business, BusinessMembership, User, Venue, Listing, Booking};
use App\Support\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);

it('persists restricted venue access and permission overrides and refuses foreign grants',function () {
    $admin=setupActor(); $owner=setupActor('provider_admin'); $b=setupBusiness(); $b->update(['owner_id'=>$owner->id]);
    $mine=Venue::forceCreate(['name'=>'My venue','status'=>'active','business_id'=>$b->id,'owner_user_id'=>$owner->id]);
    $other=Venue::forceCreate(['name'=>'Foreign venue','status'=>'active','business_id'=>setupBusiness()->id]);
    $url="/api/admin/businesses/{$b->id}/invitations";
    $base=['name'=>'QA scoped','email'=>'scoped@example.test','role'=>'staff'];
    $this->actingAs($admin)->postJson($url,$base+['location_ids'=>[$other->id]])->assertUnprocessable();
    $this->postJson($url,$base+['permissions'=>['grant'=>['manage_geography']]])->assertUnprocessable();
    $id=$this->postJson($url,$base+['location_ids'=>[$mine->id],'permissions'=>['grant'=>['manage_promocodes'],'revoke'=>['accept_payments']]])->assertOk()->json('data.user_id');
    $member=User::findOrFail($id);
    expect(AccessScope::allowedVenueIds($member))->toBe([$mine->id])
        ->and($member->capabilityOverrides())->toBe(['grant'=>['manage_promocodes'],'revoke'=>['accept_payments']])
        ->and($member->hasCapability('accept_payments'))->toBeFalse()
        ->and(BusinessMembership::where('user_id',$id)->value('scope'))->toBe('selected');
    $second=Venue::forceCreate(['name'=>'Second venue','status'=>'active','business_id'=>$b->id,'owner_user_id'=>$owner->id]);
    $this->putJson("/api/admin/users/$id",['role'=>'staff','assigned_location_ids'=>[$second->id],
        'custom_permissions'=>['grant'=>[],'revoke'=>['manage_bookings']]])->assertOk();
    expect(AccessScope::allowedVenueIds($member->fresh()))->toBe([$second->id])
        ->and($member->fresh()->capabilityOverrides())->toBe(['grant'=>[],'revoke'=>['manage_bookings']]);
    $this->putJson("/api/admin/users/$id",['custom_permissions'=>['grant'=>['manage_geography']]])->assertUnprocessable();
});
beforeEach(function () {
    config(['preview.workspace'=>true]);
    foreach (array_merge(['super_admin'],BusinessMembership::ASSIGNABLE_ROLES) as $role) Role::findOrCreate($role,'web');
});
function setupActor($role='super_admin') {
    $u=User::factory()->create(['status'=>User::ACTIVE]); $u->assignRole($role); return $u;
}
function setupBusiness() {
    return Business::create(['name'=>'QA academy','username'=>'qa-'.uniqid(),'status'=>'active','plan'=>'free','brand_enabled'=>true]);
}
it('creates a draft site and invites an owner and manager with isolated dashboard access',function () {
    $admin=setupActor();
    $id=$this->actingAs($admin)->postJson('/api/admin/businesses',[
        'business_name'=>'QA academy','username'=>'qa-academy','business_types'=>['venue'],'publish_website'=>false,'idempotency_key'=>'qa-create',
    ])->assertOk()->json('data.business.id');
    $b=Business::findOrFail($id);
    expect($b->brand_enabled)->toBeFalse()->and($b->brand_subdomain)->toBe('qa-academy');
    $ownerId=$this->postJson("/api/admin/businesses/$id/invitations",['name'=>'QA Owner','email'=>'owner@example.test','role'=>'provider_admin'])->assertOk()->assertJsonPath('data.delivery','preview')->json('data.user_id');
    $managerId=$this->postJson("/api/admin/businesses/$id/invitations",['name'=>'QA Manager','email'=>'manager@example.test','role'=>'facility_admin'])->assertOk()->json('data.user_id');
    $owner=User::find($ownerId); $manager=User::find($managerId);
    expect(AccessScope::businessId($owner))->toBe($id)->and(AccessScope::businessId($manager))->toBe($id)
        ->and($manager->owner_user_id)->toBe($ownerId)->and($manager->hasRole('platform_staff'))->toBeFalse();
    $mine=Venue::forceCreate(['name'=>'QA court','status'=>'active','business_id'=>$id,'owner_user_id'=>$ownerId,'operator_type'=>'provider']);
    $other=Venue::forceCreate(['name'=>'Other court','status'=>'active','business_id'=>setupBusiness()->id,'operator_type'=>'provider']);
    expect(AccessScope::allowedVenueIds($manager))->toContain($mine->id)->not->toContain($other->id);
    $this->actingAs($owner)->putJson('/api/business/brand',['brand_name'=>'My QA academy','brand_enabled'=>true])->assertOk();
    $this->actingAs($manager)->getJson('/api/admin/bookings/calendar-dashboard?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk();
    $this->actingAs($owner)->postJson("/api/admin/businesses/{$other->business_id}/invitations",['name'=>'Intruder','email'=>'intruder@example.test','role'=>'staff'])->assertForbidden();
});
it('rejects platform roles and a second owner through business invitations',function () {
    $b=setupBusiness(); $this->actingAs(setupActor());
    foreach(['super_admin','admin','platform_staff','country_admin'] as $role) {
        $this->postJson("/api/admin/businesses/{$b->id}/invitations",['name'=>'QA','email'=>"$role@example.test",'role'=>$role])->assertUnprocessable();
    }
    $this->postJson("/api/admin/businesses/{$b->id}/invitations",['name'=>'QA','email'=>'qa@example.test','role'=>'provider_admin'])->assertOk();
    $this->postJson("/api/admin/businesses/{$b->id}/invitations",['name'=>'QA2','email'=>'qa2@example.test','role'=>'provider_admin'])->assertUnprocessable();
});
it('keeps an ownerless business website scoped to its own venues',function () {
    $a=setupBusiness(); $b=setupBusiness();
    $mine=Venue::forceCreate(['name'=>'Owned by A','status'=>'active','business_id'=>$a->id,'operator_type'=>'provider']);
    $other=Venue::forceCreate(['name'=>'Owned by B','status'=>'active','business_id'=>$b->id,'operator_type'=>'provider']);
    $r=$this->getJson('/api/marketplace/brand-locations?brand_slug='.$a->username)->assertOk();
    $json=json_encode($r->json('data'));
    expect($json)->toContain('Owned by A')->not->toContain('Owned by B');
});
