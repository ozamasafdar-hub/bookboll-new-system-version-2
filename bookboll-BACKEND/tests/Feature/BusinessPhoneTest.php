<?php

use App\Models\{Business, BusinessMembership, User};
use App\Mail\BusinessEmailOtpEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.workspace'=>true]); Mail::fake();
    foreach (['super_admin','admin','country_admin','platform_staff','provider_admin','facility_admin','staff','customer'] as $role) Role::findOrCreate($role,'web');
});
function phoneActor(string $role): User {
    $u=User::factory()->create(['status'=>'active','password'=>'Test-phone-12345','phone'=>'+97455509999']);$u->assignRole($role);return $u;
}
function phoneBusiness(?User $owner=null): Business {
    return Business::create(['name'=>'QA Phone','username'=>'qa-phone-'.uniqid(),'owner_id'=>$owner?->id,'status'=>'active']);
}
function phonePayload(): array {
    return ['business_name'=>'QA Contact','username'=>'qa-contact-'.uniqid(),'business_types'=>['sports_facility'],'phone'=>'00974 (5550) 0001','idempotency_key'=>uniqid('phone-')];
}

it('saves a signup business number separately from the existing customer phone',function () {
    $owner=phoneActor('customer');
    $this->postJson('/api/business/email/send-otp',['email'=>$owner->email])->assertOk();
    $code=null;Mail::assertSent(BusinessEmailOtpEmail::class,function($m)use(&$code){$code=$m->otp;return true;});
    $proof=$this->postJson('/api/business/email/verify-otp',['email'=>$owner->email,'otp'=>$code])->assertOk()->json('data.email_verification_token');
    $this->postJson('/api/business/register',array_merge(phonePayload(),['name'=>$owner->name,'email'=>$owner->email,'password'=>'Test-phone-12345','password_confirmation'=>'Test-phone-12345','email_verification_token'=>$proof]))->assertSuccessful();
    expect(Business::where('owner_id',$owner->id)->firstOrFail()->phone)->toBe('+97455500001');
    expect($owner->fresh()->phone)->toBe('+97455509999');
});

it('requires an international phone at both creation endpoints without writing records',function ($phone) {
    $payload=phonePayload();$payload['phone']=$phone;
    $this->actingAs(phoneActor('super_admin'))->postJson('/api/admin/businesses',$payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->postJson('/api/business/register',array_merge($payload,['name'=>'QA','email'=>'qa-phone@example.test','password'=>'Test-phone-12345','password_confirmation'=>'Test-phone-12345','email_verification_token'=>'invalid-proof']))->assertUnprocessable()->assertJsonValidationErrors('phone');
    expect(Business::count())->toBe(0);
})->with([null,'','55500001','+974','+974abc55500001','https://wa.me/97455500001','+1234567890123456','+0000000000','array'=>[['+97455500001']]]);

it('persists admin-created contact numbers and retries do not overwrite them',function () {
    $actor=phoneActor('super_admin');$payload=phonePayload();
    $first=$this->actingAs($actor)->postJson('/api/admin/businesses',$payload)->assertOk()->assertJsonPath('data.business.phone','+97455500001')->json('data.business.id');
    $payload['phone']='+97455500002';
    $this->postJson('/api/admin/businesses',$payload)->assertOk()->assertJsonPath('data.business.id',$first)->assertJsonPath('data.business.phone','+97455500001');
    expect(Business::count())->toBe(1)->and($actor->fresh()->phone)->toBe('+97455509999');
});

it('lets only superadmins edit another business contact including ownerless businesses',function ($role) {
    $b=phoneBusiness();$this->actingAs(phoneActor($role));
    $result=$this->putJson('/api/admin/businesses/'.$b->id.'/contact',['phone'=>'+974 5550 0002']);
    if ($role==='super_admin') {
        $result->assertOk()->assertJsonPath('data.business.phone','+97455500002');
        expect($b->fresh()->phone)->toBe('+97455500002');
        $this->putJson('/api/admin/businesses/'.$b->id.'/contact',['phone'=>''])->assertUnprocessable();
        expect($b->fresh()->phone)->toBe('+97455500002');
    } else { $result->assertForbidden(); expect($b->fresh()->phone)->toBeNull(); }
})->with(['super_admin','admin','country_admin','platform_staff','provider_admin','facility_admin','staff']);

it('keeps provider contact edits within its own business and separate from personal details',function () {
    $owner=phoneActor('provider_admin');$mine=phoneBusiness($owner);$other=phoneBusiness();
    $this->actingAs($owner)->putJson('/api/business/settings/profile',['name'=>'QA renamed','phone'=>'+974 (5550) 0003','business_id'=>$other->id])->assertOk()->assertJsonPath('data.business.phone','+97455500003');
    expect($mine->fresh()->phone)->toBe('+97455500003')->and($other->fresh()->phone)->toBeNull()->and($owner->fresh()->phone)->toBe('+97455509999');
    $staff=phoneActor('facility_admin');BusinessMembership::create(['business_id'=>$mine->id,'user_id'=>$staff->id,'role'=>'facility_admin','scope'=>'all','status'=>'active']);
    $this->actingAs($staff)->putJson('/api/business/settings/profile',['name'=>'No','phone'=>'+97455500004'])->assertForbidden();
    expect($mine->fresh()->phone)->toBe('+97455500003');
});

it('returns and searches business phones without substituting owner personal phones',function () {
    $owner=phoneActor('provider_admin');$missing=phoneBusiness($owner);$contact=phoneBusiness();$contact->update(['phone'=>'+97455500001']);
    $this->actingAs(phoneActor('super_admin'));
    $rows=collect($this->getJson('/api/admin/businesses')->assertOk()->json('data.businesses.data'));
    expect($rows->firstWhere('id',$missing->id)['phone'])->toBeNull();
    $this->getJson('/api/admin/businesses?search=55500001')->assertOk()->assertJsonPath('data.businesses.total',1)->assertJsonPath('data.businesses.data.0.phone','+97455500001');
});
