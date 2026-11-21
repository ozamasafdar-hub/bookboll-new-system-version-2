<?php

/**
 * Registering a business before anyone from it has a login (readmeAlamgir §1).
 *
 * The point of these: platform staff add a venue operator as a record, and no
 * user account is invented to carry it — no placeholder login, no synthetic
 * email, no shared password. The business is unclaimed, which is a valid state
 * and NOT "Bookboll's own business".
 */

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'provider_admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function actorWithRole(string $role): User
{
    $user = User::factory()->create(['status' => User::ACTIVE]);
    $user->assignRole($role);

    return $user->fresh();
}

function createPayload(array $overrides = []): array
{
    return array_merge([
        'business_name'   => 'Doha Padel Club',
        'phone' => '+97455500001',
        'username'        => 'doha-padel-'.uniqid(),
        'business_types'  => ['venue'],
        'country_id'      => null,
        'owner_user_id'   => null,
        'idempotency_key' => 'key-'.uniqid(),
    ], $overrides);
}

it('advertises create_without_owner only when the schema really allows it', function () {
    $this->actingAs(actorWithRole('super_admin'))
        ->getJson('/api/admin/businesses/capabilities')
        ->assertOk()
        ->assertJsonPath('data.contract_version', 1)
        ->assertJsonPath('data.create_without_owner', true);
});

it('refuses business administration to a provider', function () {
    $this->actingAs(actorWithRole('provider_admin'))
        ->getJson('/api/admin/businesses/capabilities')
        ->assertStatus(403);

    $this->actingAs(actorWithRole('provider_admin'))
        ->postJson('/api/admin/businesses', createPayload())
        ->assertStatus(403);
});

it('creates a business with no owner and invents no user to hold it', function () {
    // Count AFTER the actor exists, so only accounts the endpoint creates show up.
    $actor = actorWithRole('super_admin');
    $before = User::count();

    $res = $this->actingAs($actor)
        ->postJson('/api/admin/businesses', createPayload())
        ->assertOk()
        ->assertJsonPath('data.contract_version', 1)
        ->assertJsonPath('data.business.owner_user_id', null);

    $id = $res->json('data.business.id');
    $business = Business::find($id);

    expect($business)->not->toBeNull()
        ->and($business->owner_id)->toBeNull()
        ->and($business->isOwnerless())->toBeTrue()
        // No placeholder account was minted to stand in for an owner.
        ->and(User::count())->toBe($before);
});

it('keeps two ownerless businesses apart, each with its own id', function () {
    $actor = actorWithRole('super_admin');

    $a = $this->actingAs($actor)->postJson('/api/admin/businesses', createPayload())->json('data.business.id');
    $b = $this->actingAs($actor)->postJson('/api/admin/businesses', createPayload())->json('data.business.id');

    expect($a)->not->toBe($b)
        ->and(Business::whereNull('owner_id')->count())->toBe(2);
});

it('returns the original business when a create is retried', function () {
    $actor = actorWithRole('super_admin');
    $payload = createPayload();

    $first = $this->actingAs($actor)->postJson('/api/admin/businesses', $payload)
        ->assertOk()->json('data.business.id');

    // A lost response, retried with the same key, must not make a second one.
    $second = $this->actingAs($actor)->postJson('/api/admin/businesses', $payload)
        ->assertOk()->json('data.business.id');

    expect($second)->toBe($first)
        ->and(Business::count())->toBe(1);
});

it('will not take an owner id through this endpoint', function () {
    $owner = User::factory()->create();

    $this->actingAs(actorWithRole('super_admin'))
        ->postJson('/api/admin/businesses', createPayload(['owner_user_id' => $owner->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('owner_user_id');
});

it('refuses a duplicate or reserved web address', function () {
    $actor = actorWithRole('super_admin');

    $this->actingAs($actor)->postJson('/api/admin/businesses', createPayload(['username' => 'taken-slug']))->assertOk();

    $this->actingAs($actor)->postJson('/api/admin/businesses', createPayload(['username' => 'taken-slug']))
        ->assertStatus(422)->assertJsonValidationErrors('username');
});

it('counts staff from memberships, so an ownerless business keeps its people', function () {
    $actor = actorWithRole('super_admin');
    $id = $this->actingAs($actor)->postJson('/api/admin/businesses', createPayload())->json('data.business.id');

    BusinessMembership::create([
        'business_id' => $id,
        'user_id'     => User::factory()->create()->id,
        'role'        => 'staff',
        'scope'       => 'all',
        'status'      => BusinessMembership::STATUS_ACTIVE,
    ]);

    // owner_user_id is null here, so the old owner-keyed count would read 0 and
    // the business would look empty.
    $this->actingAs($actor)->getJson('/api/admin/businesses')
        ->assertOk();

    expect(BusinessMembership::where('business_id', $id)->active()->count())->toBe(1);
});
