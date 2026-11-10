<?php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('rejects copied credentials and financial writes in preview mode', function () {
    config(['preview.enabled'=>true,'preview.admin_email'=>'preview-admin@bookboll.com']);
    $this->postJson('/api/login',['email'=>'copied-user@example.com','password'=>'anything'])->assertForbidden();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertUnauthorized();
    $copied=User::factory()->create();
    $this->actingAs($copied,'sanctum')->getJson('/api/admin/payments/dashboard?report_version=2')->assertUnauthorized();
    $preview=User::factory()->create(['email'=>'preview-admin@bookboll.com']);
    $this->actingAs($preview,'sanctum')->postJson('/api/admin/venues/1/transfers',[])->assertForbidden();
});

it('opens public browsing but refuses copied accounts and real integrations in the workspace', function () {
    $copied = User::factory()->create(['email'=>'copied@example.com']);
    config(['preview.enabled'=>true,'preview.workspace'=>true]);
    $this->postJson('/api/login',['email'=>$copied->email,'password'=>'password'])->assertForbidden();
    $this->postJson('/api/register',['email'=>$copied->email])->assertStatus(422);
    $this->postJson('/api/admin-app/register',['email'=>$copied->email])->assertUnauthorized();
    $this->getJson('/api/business/countries')->assertOk();
    $this->postJson('/api/customer/payments/create-checkout-session')->assertStatus(409);
    $this->postJson('/api/admin/notifications/email')->assertStatus(409);
    $this->actingAs($copied,'sanctum')->getJson('/api/me')->assertForbidden();
    $new = User::factory()->create();
    expect((bool)$new->workspace_access)->toBeTrue();
    $this->actingAs($new,'sanctum')->getJson('/api/me')->assertOk();
});

it('supplies a real one-time code for new workspace signup without enabling copied identities', function () {
    config(['preview.enabled'=>true,'preview.workspace'=>true]);
    \Illuminate\Support\Facades\Mail::fake();
    $res = $this->postJson('/api/business/email/send-otp',['email'=>'qa-workspace@example.com'])->assertOk();
    $code = $res->json('data.workspace_code');
    expect($code)->toMatch('/^\d{6}$/');
    $this->postJson('/api/business/email/verify-otp',['email'=>'qa-workspace@example.com','otp'=>$code])
        ->assertOk()->assertJsonStructure(['data'=>['email_verification_token']]);
});
