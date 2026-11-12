<?php

/**
 * Premium is sold one way: US$588 a year, paid when subscribing (readmeAlamgir §6).
 *
 * "This frontend cannot guarantee that the current backend will stop creating
 * trials" — so these pin the server side. A trial created by the server cannot
 * be switched off by the browser, which is why the only safe guarantee is never
 * asking Stripe for one.
 */

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\User;
use App\Services\ProviderPlanService;
use App\Support\PremiumPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('provider_admin', 'web'));

function premiumOwner(): User
{
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $owner->assignRole('provider_admin');
    Business::create([
        'owner_id' => $owner->id, 'name' => 'Biz', 'username' => 'biz-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    return $owner->fresh();
}

it('previews a plan promo against the signed in business and actual annual price',function(){
    $owner=premiumOwner();
    \App\Models\Promocode::create(['code'=>'QAPLAN10','description'=>'QA plan promo','status'=>'active','applicable_to'=>'provider_plan','recipient'=>'bookboll','owner_user_id'=>$owner->id,'discount_type'=>'percentage','discount_value'=>10]);
    $this->actingAs($owner,'sanctum')->postJson('/api/promocodes/apply',['promocode'=>'QAPLAN10','order_amount'=>1,'applicable_to'=>'provider_plan'])
        ->assertOk()->assertJsonPath('data.promocode.discount_amount',58.8)->assertJsonPath('data.promocode.new_order_amount',529.2);
});

it('refuses a monthly checkout instead of quietly charging a year', function () {
    $this->actingAs(premiumOwner())
        ->postJson('/api/admin/plan/checkout', ['billing' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('billing');
});

it('refuses any billing period that is not the annual offer', function (string $billing) {
    $this->actingAs(premiumOwner())
        ->postJson('/api/admin/plan/checkout', ['billing' => $billing])
        ->assertStatus(422);
})->with(['monthly', 'weekly', 'lifetime', '']);

it('never asks Stripe for a trial period', function () {
    // The payload builder is the only place a trial could be requested.
    $source = file_get_contents(app_path('Services/ProviderPlanService.php'));
    $payload = substr($source, strpos($source, 'function planSessionPayload'));
    $payload = substr($payload, 0, strpos($payload, "\n    }"));

    // Strip comments first — the code explains WHY there is no trial, and the
    // explanation naturally names the key it is not setting.
    $code = preg_replace('#//.*$#m', '', $payload);

    expect($code)->not->toContain('trial_period_days')
        ->and($code)->not->toContain('trial_end');
});

it('tells the dashboard there is no trial and no monthly option', function () {
    $res = $this->actingAs(premiumOwner())->getJson('/api/admin/plan')->assertOk();

    expect($res->json('data.pricing.trial_days'))->toBe(0)
        ->and($res->json('data.pricing.monthly_available'))->toBeFalse()
        ->and($res->json('data.pricing.yearly_total'))->toBe(PremiumPlan::PRICE_YEARLY);
});

it('leaves an existing monthly subscription exactly as it is', function () {
    $owner = premiumOwner();
    $business = Business::where('owner_id', $owner->id)->first();

    // A legacy contract. readmeAlamgir: "Do not silently bill, cancel or
    // migrate them."
    $sub = BusinessSubscription::create([
        'business_id' => $business->id, 'plan' => 'premium', 'billing' => 'monthly',
        'status' => 'active', 'amount' => PremiumPlan::PRICE_MONTHLY, 'currency' => 'USD',
        'current_period_end' => now()->addDays(20),
    ]);
    $business->update(['plan' => 'premium']);

    $this->actingAs($owner->fresh())->getJson('/api/admin/plan')->assertOk();

    $sub->refresh();
    expect($sub->billing)->toBe('monthly')
        ->and((float) $sub->amount)->toBe((float) PremiumPlan::PRICE_MONTHLY)
        ->and($sub->status)->toBe('active');
});

it('still reports a legacy trial honestly rather than hiding it', function () {
    $owner = premiumOwner();
    $business = Business::where('owner_id', $owner->id)->first();
    BusinessSubscription::create([
        'business_id' => $business->id, 'plan' => 'premium', 'billing' => 'yearly',
        'status' => 'trialing', 'amount' => PremiumPlan::PRICE_YEARLY, 'currency' => 'USD',
        'trial_ends_at' => now()->addDays(9), 'current_period_end' => now()->addDays(9),
    ]);
    $business->update(['plan' => 'premium']);

    $res = $this->actingAs($owner->fresh())->getJson('/api/admin/plan')->assertOk();

    // Shown, because hiding account history would be misleading...
    expect($res->json('data.status'))->toBe('trialing')
        ->and($res->json('data.trial_ends_at'))->not->toBeNull()
        // ...but a trial has paid nothing, so it earns no commission discount.
        ->and($res->json('data.own_site_commission_discount_eligible'))->toBeFalse();
});
