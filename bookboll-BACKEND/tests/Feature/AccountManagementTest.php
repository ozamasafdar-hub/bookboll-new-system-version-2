<?php

use App\Models\{AuditLog, Business, BusinessSubscription, User};
use App\Support\{CommissionPolicy, PremiumPlan};
use App\Services\ProviderPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['preview.workspace'=>true, 'preview.enabled'=>true]);
    Mail::fake();
    foreach (['super_admin','admin','country_admin','platform_staff','provider_admin','facility_admin','staff','provider_viewer','customer'] as $role) Role::findOrCreate($role, 'web');
});
function managementUser(string $role = 'super_admin'): User {
    $u = User::factory()->create(['status'=>User::ACTIVE, 'password'=>'old-password-1234']); $u->assignRole($role); return $u;
}
function managementBusiness(): Business {
    $owner = managementUser('provider_admin');
    return Business::forceCreate(['name'=>'QA managed business', 'username'=>'qa-'.uniqid(), 'owner_id'=>$owner->id, 'plan'=>'free', 'status'=>'active']);
}
function managementPlanPayload(array $data = []): array {
    return array_merge(['plan'=>'premium','expected_plan'=>'free','expected_override'=>null,'reason'=>'Complimentary account requested'], $data);
}

it('allows only a platform superadmin to use security and plan management', function ($role) {
    $target=managementUser('platform_staff'); $business=managementBusiness();
    $this->actingAs(managementUser($role));
    $this->getJson("/api/admin/users/$target->id/security")->assertForbidden();
    $this->postJson("/api/admin/users/$target->id/security",['action'=>'revoke_sessions','reason'=>'QA test session'])->assertForbidden();
    $this->getJson("/api/admin/businesses/$business->id/plan-management")->assertForbidden();
    $this->putJson("/api/admin/businesses/$business->id/plan-management",managementPlanPayload())->assertForbidden();
})->with(['admin','country_admin','platform_staff','provider_admin','facility_admin','customer']);

it('changes platform and provider passwords revokes sessions and never records the password',function ($role) {
    $actor=managementUser(); $target=managementUser($role);
    $target->createToken('old-device');
    DB::table('password_reset_tokens')->insert(['email'=>$target->email,'token'=>'old','created_at'=>now()]);
    $this->actingAs($actor)->postJson("/api/admin/users/$target->id/security", ['action'=>'password','reason'=>'Account holder needs help','password'=>'New-password-12345','password_confirmation'=>'New-password-12345'])->assertOk();
    expect(Hash::check('New-password-12345',$target->fresh()->password))->toBeTrue();
    expect($target->tokens()->count())->toBe(0);
    expect(DB::table('password_reset_tokens')->where('email',$target->email)->exists())->toBeFalse();
    $audit=AuditLog::where('action','account.password')->firstOrFail();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->details)->not->toContain('New-password');
    $this->getJson("/api/admin/users/$target->id/security")->assertOk()->assertJsonPath('data.active_sessions',0);
})->with(['platform_staff','provider_admin','facility_admin']);

it('rejects weak mismatched passwords self resets and customer targets',function () {
    $actor=managementUser();$target=managementUser('staff');$this->actingAs($actor);
    foreach(['short','onlyletterspassword','1234567890123'] as $password) $this->postJson("/api/admin/users/$target->id/security",['action'=>'password','reason'=>'QA account request','password'=>$password,'password_confirmation'=>$password])->assertUnprocessable();
    $this->postJson("/api/admin/users/$target->id/security",['action'=>'password','reason'=>'QA account request','password'=>'Valid-password-123','password_confirmation'=>'other'])->assertUnprocessable();
    $this->postJson("/api/admin/users/$actor->id/security",['action'=>'revoke_sessions','reason'=>'QA account request'])->assertUnprocessable();
    $customer=managementUser('customer');$this->getJson("/api/admin/users/$customer->id/security")->assertUnprocessable();
});

it('simulates reset email in the workspace and revokes sessions without changing a password',function () {
    $target=managementUser('platform_staff');$hash=$target->password;$target->createToken('device');$this->actingAs(managementUser());
    $this->postJson("/api/admin/users/$target->id/security",['action'=>'reset_email','reason'=>'User requested recovery'])->assertOk()->assertJsonPath('data.delivery','preview');
    Mail::assertNothingSent();expect($target->tokens()->count())->toBe(1);
    $this->postJson("/api/admin/users/$target->id/security",['action'=>'revoke_sessions','reason'=>'User lost their device'])->assertOk();
    expect($target->fresh()->password)->toBe($hash)->and($target->tokens()->count())->toBe(0);
});

it('grants complimentary premium including its website fee and supports reverting to free', function () {
    $b=managementBusiness();$actor=managementUser();$this->actingAs($actor);
    $this->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload())->assertOk();
    expect(PremiumPlan::businessEntitled($b->fresh()))->toBeTrue()->and(CommissionPolicy::paidPlanEligible($b->fresh()))->toBeTrue();
    expect(BusinessSubscription::count())->toBe(0);
    $this->actingAs($b->owner)->getJson('/api/admin/plan')->assertOk()->assertJsonPath('data.admin_managed',true)->assertJsonPath('data.own_site_commission_discount_eligible',true);
    $this->actingAs($actor)->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload(['plan'=>'free','expected_plan'=>'premium','expected_override'=>'premium']))->assertOk();
    expect($b->fresh()->plan)->toBe('free')->and(CommissionPolicy::paidPlanEligible($b->fresh()))->toBeFalse();
    expect(AuditLog::where('action','business.plan_changed')->count())->toBe(2);
});

it('preserves manual entitlement during subscription sync and allows returning to normal billing', function () {
    $b=managementBusiness();$this->actingAs(managementUser())->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload())->assertOk();
    $stale=Business::find($b->id);$stale->plan_override=null;
    $method=new ReflectionMethod(ProviderPlanService::class,'applyEntitlement');$method->invoke(app(ProviderPlanService::class),$stale,null);
    expect($b->fresh()->plan)->toBe('premium');
    $this->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload(['plan'=>'billing','expected_plan'=>'premium','expected_override'=>'premium']))->assertOk();
    expect($b->fresh()->plan)->toBe('free')->and($b->fresh()->plan_override)->toBeNull();
});

it('rejects stale reviews and active billing conflicts', function () {
    $b=managementBusiness();$this->actingAs(managementUser());
    $this->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload(['expected_plan'=>'premium']))->assertConflict();
    BusinessSubscription::create(['business_id'=>$b->id,'plan'=>'premium','billing'=>'yearly','status'=>'active','amount'=>588,'currency'=>'USD','stripe_subscription_id'=>'sub_qa']);
    $this->getJson("/api/admin/businesses/$b->id/plan-management")->assertOk()->assertJsonPath('data.billing_conflict',true);
    $this->putJson("/api/admin/businesses/$b->id/plan-management",managementPlanPayload())->assertConflict();
    expect($b->fresh()->plan_override)->toBeNull();
});

it('blocks legacy password updates and preserves access when validation fails',function () {
    $target=managementUser('platform_staff');$target->createToken('device');
    $this->actingAs(managementUser())->putJson("/api/admin/users/$target->id",['password'=>'insecure'])->assertUnprocessable();
    expect(Hash::check('old-password-1234',$target->fresh()->password))->toBeTrue()->and($target->tokens()->count())->toBe(1);
});

it('deactivates accounts with immediate session revocation and refuses subsequent sign-in',function () {
    $target=managementUser('platform_staff');$target->createToken('device');
    $this->actingAs(managementUser())->putJson("/api/admin/users/$target->id",['status'=>'inactive'])->assertOk();
    expect($target->tokens()->count())->toBe(0);
    $this->postJson('/api/login',['email'=>$target->email,'password'=>'old-password-1234'])->assertForbidden();
    // A customer token carries the same account roles, so it cannot be a way
    // around account deactivation into the admin application.
    $this->postJson('/api/customer/login',['email'=>$target->email,'password'=>'old-password-1234'])->assertJsonPath('success',false);
});

it('prevents self lockout and unauthorized superadmin grants', function () {
    $actor=managementUser();$this->actingAs($actor);
    $this->putJson("/api/admin/users/$actor->id",['status'=>'inactive'])->assertUnprocessable();
    $target=managementUser('platform_staff');$this->actingAs(managementUser('admin'));
    $this->putJson("/api/admin/users/$target->id",['role'=>'super_admin'])->assertForbidden();
});

it('creates staff without a shared password and preserves an existing customer password', function () {
    $this->actingAs(managementUser());
    $this->postJson('/api/admin/users',['name'=>'QA new staff','email'=>'new-qa@example.test','role'=>'platform_staff','status'=>'active'])->assertOk();
    $user=User::where('email','new-qa@example.test')->firstOrFail();expect(Hash::check('12345678',$user->password))->toBeFalse();Mail::assertNothingSent();
    $customer=managementUser('customer');$customer->createToken('customer-device');
    $this->postJson('/api/admin/users',['name'=>$customer->name,'email'=>$customer->email,'role'=>'platform_staff','status'=>'active'])->assertOk();
    expect(Hash::check('old-password-1234',$customer->fresh()->password))->toBeTrue()->and($customer->tokens()->count())->toBe(0);
});

it('round trips custom permissions and updates membership-only team roles without losing their business', function () {
    $b=managementBusiness();$target=managementUser('facility_admin');
    $target->forceFill(['custom_permissions'=>['grant'=>[], 'revoke'=>['manage_promocodes']]])->save();
    $member=\App\Models\BusinessMembership::create(['business_id'=>$b->id, 'user_id'=>$target->id, 'role'=>'facility_admin', 'scope'=>'all', 'status'=>'active', 'version'=>1]);
    $this->actingAs(managementUser());
    $response=$this->getJson('/api/admin/users?scope=provider_staff')->assertOk();
    $row=collect($response->json('data.users.data'))->firstWhere('id',$target->id);
    expect($row['custom_permissions']['revoke'])->toBe(['manage_promocodes']);
    $this->putJson("/api/admin/users/$target->id",['role'=>'staff','owner_user_id'=>null])->assertOk();
    expect($member->fresh()->role)->toBe('staff')->and(\App\Support\AccessScope::businessId($target->fresh()))->toBe($b->id);
    expect($target->fresh()->custom_permissions['revoke'])->toBe(['manage_promocodes']);
});

it('emails a reset code outside preview without changing the current password',function () {
    config(['preview.workspace'=>false,'preview.enabled'=>false]);
    $target=managementUser('platform_staff');$hash=$target->password;
    $this->actingAs(managementUser())->postJson("/api/admin/users/$target->id/security",['action'=>'reset_email','reason'=>'Account holder requested reset'])->assertOk()->assertJsonPath('data.delivery','sent');
    Mail::assertSent(\App\Mail\CustomerForgotPasswordOtpEmail::class);
    expect($target->fresh()->password)->toBe($hash);
});

it('keeps provider account editors inside their own business on the shared admin API',function () {
    $a=managementBusiness();$b=managementBusiness();$staff=managementUser('staff');$staff->update(['owner_user_id'=>$b->owner_id]);
    $this->actingAs($a->owner)->putJson("/api/admin/users/$staff->id",['name'=>'Unauthorized change'])->assertForbidden();
    $this->postJson('/api/admin/users',['name'=>'Outside staff','email'=>'outside@example.test','role'=>'staff','status'=>'active','owner_user_id'=>$b->owner_id])->assertForbidden();
    $this->postJson('/api/admin/users',['name'=>'Extra owner','email'=>'extra-owner@example.test','role'=>'provider_admin','status'=>'active'])->assertForbidden();
    expect($staff->fresh()->name)->not->toBe('Unauthorized change');
});
