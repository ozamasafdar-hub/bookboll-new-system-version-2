<?php

/**
 * Staff invited under a business owner must belong to that business even when
 * the business is OWNERLESS (businesses.owner_id null, owner attached through a
 * membership). Live case: user 40183 (ozama@yopmail.com), a facility_admin
 * invited under Badminton City's owner, signed in to an empty dashboard because
 * the legacy owner link resolved to no business.
 */

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\BusinessMembershipSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (array_merge(
        ['super_admin', 'admin', 'provider_admin', 'platform_staff', 'customer', 'senior_employee', 'junior_employee', 'guard'],
        BusinessMembership::ASSIGNABLE_ROLES
    ) as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function lsmSuperAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

/** An ownerless business whose owner is attached by membership, with two venues. */
function lsmOwnerlessBusinessWithOwner(): array
{
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $owner->assignRole('provider_admin');

    $business = Business::create([
        'owner_id' => null, 'name' => 'Badminton City', 'username' => 'bc-'.uniqid(),
        'status' => Business::STATUS_ACTIVE,
    ]);

    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'provider_admin',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);

    // business_id is deliberately not mass-assignable on Venue; set it the way
    // the venue flows do.
    $venues = collect([1, 2])->map(fn ($i) => tap(
        Venue::create(['name' => "Court {$i}", 'status' => 'active']),
        fn (Venue $v) => $v->forceFill(['business_id' => $business->id])->save()
    ));

    return [$owner->fresh(), $business, $venues];
}

/** The pre-fix shape: a facility_admin pointed at the owner, with no membership. */
function lsmLegacyManagerUnder(User $owner): User
{
    $m = User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => $owner->id]);
    $m->assignRole('facility_admin');

    return $m->fresh();
}

it('resolves a legacy-linked manager to the owner\'s ownerless business and its venues', function () {
    [$owner, $business, $venues] = lsmOwnerlessBusinessWithOwner();
    $manager = lsmLegacyManagerUnder($owner);

    // No membership yet — exactly the live row — and it still resolves.
    expect(AccessScope::businessId($manager))->toBe($business->id)
        ->and(AccessScope::allowedVenueIds($manager))->toEqualCanonicalizing($venues->pluck('id')->all());
});

it('creates the membership when an admin invites a manager under an ownerless business owner', function () {
    [$owner, $business, $venues] = lsmOwnerlessBusinessWithOwner();

    $res = $this->actingAs(lsmSuperAdmin())->postJson('/api/admin/users', [
        'name' => 'osama', 'email' => 'ozama@yopmail.com', 'role' => 'facility_admin',
        'status' => User::ACTIVE, 'owner_user_id' => $owner->id,
    ]);
    $res->assertOk();

    $manager = User::where('email', 'ozama@yopmail.com')->firstOrFail();

    $membership = BusinessMembership::where('user_id', $manager->id)->active()->first();
    expect($membership)->not->toBeNull()
        ->and($membership->business_id)->toBe($business->id)
        ->and($membership->role)->toBe('facility_admin')
        ->and($membership->scope)->toBe('all')
        ->and($res->json('data.user.business_id'))->toBe($business->id)
        ->and(AccessScope::allowedVenueIds($manager))->toEqualCanonicalizing($venues->pluck('id')->all());
});

it('keeps venue pins on the legacy columns, never on the membership', function () {
    [$owner, $business, $venues] = lsmOwnerlessBusinessWithOwner();
    $pinned = $venues->first();

    $this->actingAs(lsmSuperAdmin())->postJson('/api/admin/users', [
        'name' => 'Front desk', 'email' => 'desk@example.com', 'role' => 'staff',
        'status' => User::ACTIVE, 'owner_user_id' => $owner->id,
        'assigned_location_ids' => [$pinned->id],
    ])->assertOk();

    $staff = User::where('email', 'desk@example.com')->firstOrFail();
    $membership = BusinessMembership::where('user_id', $staff->id)->active()->firstOrFail();

    expect($membership->scope)->toBe('all')
        ->and($membership->location_ids)->toBeNull()
        ->and(AccessScope::allowedVenueIds($staff))->toBe([$pinned->id]);
});

it('records legacy employee roles as staff memberships', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    $this->actingAs(lsmSuperAdmin())->postJson('/api/admin/users', [
        'name' => 'Gate', 'email' => 'guard@example.com', 'role' => 'guard',
        'status' => User::ACTIVE, 'owner_user_id' => $owner->id,
    ])->assertOk();

    $guard = User::where('email', 'guard@example.com')->firstOrFail();

    expect(BusinessMembership::where('user_id', $guard->id)->active()->value('role'))->toBe('staff')
        ->and($guard->hasRole('guard'))->toBeTrue()
        ->and(AccessScope::businessId($guard))->toBe($business->id);
});

it('moves the membership when the owner changes and revokes it when the role leaves the business', function () {
    [$ownerA, $businessA] = lsmOwnerlessBusinessWithOwner();
    [$ownerB, $businessB] = lsmOwnerlessBusinessWithOwner();
    $admin = lsmSuperAdmin();

    $this->actingAs($admin)->postJson('/api/admin/users', [
        'name' => 'Mover', 'email' => 'mover@example.com', 'role' => 'facility_admin',
        'status' => User::ACTIVE, 'owner_user_id' => $ownerA->id,
    ])->assertOk();
    $mover = User::where('email', 'mover@example.com')->firstOrFail();

    $this->actingAs($admin)->putJson("/api/admin/users/{$mover->id}", [
        'owner_user_id' => $ownerB->id,
    ])->assertOk();

    expect(BusinessMembership::where('user_id', $mover->id)->active()->pluck('business_id')->all())->toBe([$businessB->id])
        ->and(BusinessMembership::where('user_id', $mover->id)->where('business_id', $businessA->id)->value('status'))->toBe('revoked');

    $this->actingAs($admin)->putJson("/api/admin/users/{$mover->id}", [
        'role' => 'platform_staff',
    ])->assertOk();

    expect(BusinessMembership::where('user_id', $mover->id)->active()->count())->toBe(0)
        ->and($mover->fresh()->owner_user_id)->toBeNull();
});

it('backfills memberships for existing legacy-linked staff and reports the unresolvable', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();
    $manager = lsmLegacyManagerUnder($owner);

    // An owner with no business at all: nothing to attach to, must be reported, not invented.
    $orphanOwner = User::factory()->create(['status' => User::ACTIVE]);
    $orphanOwner->assignRole('provider_admin');
    $orphan = lsmLegacyManagerUnder($orphanOwner->fresh());

    // Already a member: not pending, not touched.
    $settled = lsmLegacyManagerUnder($owner);
    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $settled->id, 'role' => 'facility_admin',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);

    $pending = collect(BusinessMembershipSync::pendingBackfill());
    expect($pending->pluck('user_id')->all())->toEqualCanonicalizing([$manager->id, $orphan->id])
        ->and($pending->firstWhere('user_id', $manager->id)['business_id'])->toBe($business->id)
        ->and($pending->firstWhere('user_id', $orphan->id)['business_id'])->toBeNull();

    expect(BusinessMembershipSync::backfill())->toBe(['created' => 1, 'unresolved' => 1]);

    expect(BusinessMembership::where('user_id', $manager->id)->active()->value('business_id'))->toBe($business->id)
        ->and(BusinessMembership::where('user_id', $orphan->id)->exists())->toBeFalse()
        ->and(BusinessMembership::where('user_id', $settled->id)->count())->toBe(1);

    // Second run: nothing left to do.
    expect(BusinessMembershipSync::backfill())->toBe(['created' => 0, 'unresolved' => 1]);
});

it('never touches an owner membership', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();
    $principal = User::factory()->create(['status' => User::ACTIVE]);
    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $principal->id, 'role' => BusinessMembership::ROLE_OWNER,
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);

    // A platform role with no owner would revoke staff memberships; the owner row stays.
    $principal->assignRole('platform_staff');
    BusinessMembershipSync::syncForStaff($principal->fresh(), null);

    expect(BusinessMembership::where('user_id', $principal->id)->active()->value('role'))->toBe(BusinessMembership::ROLE_OWNER);
});

it('keeps an assigned member\'s membership when the Team page re-sends a null owner on an unrelated edit', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    // The shape BusinessAssignmentService leaves behind for an ownerless
    // business: membership present, owner_user_id null (business->owner_id).
    $member = User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => null]);
    $member->assignRole('facility_admin');
    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $member->id, 'role' => 'facility_admin',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);

    // Editing a phone number: the dashboard sends owner_user_id: null alongside.
    $this->actingAs(lsmSuperAdmin())->putJson("/api/admin/users/{$member->id}", [
        'phone' => '31005052', 'owner_user_id' => null, 'role' => 'facility_admin',
    ])->assertOk();

    expect(BusinessMembership::where('user_id', $member->id)->active()->value('business_id'))->toBe($business->id)
        ->and(AccessScope::businessId($member->fresh()))->toBe($business->id);
});

/*
 * My Website and the other owner-keyed pages for an ownerless business. These
 * all read the business through its owner's user id; for a business created
 * from "Add business" that id finds no row, so the owner, an invited manager
 * and an assigned member were each told "No business found" — and an assigned
 * member, carrying no owner id at all, fell through to the PLATFORM's settings.
 */

it('lets a manager invited under an ownerless business read and publish its website', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    $this->actingAs(lsmSuperAdmin())->postJson('/api/admin/users', [
        'name' => 'osama', 'email' => 'ozama@yopmail.com', 'role' => 'facility_admin',
        'status' => User::ACTIVE, 'owner_user_id' => $owner->id,
    ])->assertOk();
    $manager = User::where('email', 'ozama@yopmail.com')->firstOrFail();

    $show = $this->actingAs($manager)->getJson('/api/admin/site-settings')->assertOk();
    expect($show->json('data.mode'))->toBe('provider')
        ->and($show->json('data.username'))->toBe($business->username)
        ->and($show->json('data.counts.venues'))->toBe(0);

    $this->actingAs($manager)->putJson('/api/admin/site-settings', ['brand_enabled' => true])->assertOk();

    expect($business->fresh()->brand_enabled)->toBeTrue();
});

it('keeps an assigned member with no owner link on their own business, never the platform', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    $member = User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => null]);
    $member->assignRole('facility_admin');
    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $member->id, 'role' => 'facility_admin',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);
    $member = $member->fresh();

    expect(AccessScope::ownerUserId($member))->toBe($owner->id);

    $show = $this->actingAs($member)->getJson('/api/admin/site-settings')->assertOk();
    expect($show->json('data.mode'))->toBe('provider')
        ->and($show->json('data.username'))->toBe($business->username);

    $this->actingAs($member)->putJson('/api/admin/site-settings', ['brand_enabled' => true])->assertOk();

    expect($business->fresh()->brand_enabled)->toBeTrue()
        ->and(\App\Models\Setting::where('key', 'brand_enabled')->exists())->toBeFalse();
});

it('refuses the platform settings to a provider account that resolves to no business', function () {
    $stray = User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => null]);
    $stray->assignRole('facility_admin');

    $this->actingAs($stray->fresh())->putJson('/api/admin/site-settings', ['brand_enabled' => true])->assertStatus(404);

    expect(\App\Models\Setting::where('key', 'brand_enabled')->exists())->toBeFalse();
});

it('lets the owner of an ownerless business edit the business profile', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    $show = $this->actingAs($owner)->getJson('/api/business/settings')->assertOk();
    expect($show->json('data.can_edit_business'))->toBeTrue()
        ->and($show->json('data.business.name'))->toBe($business->name);

    $this->actingAs($owner)->putJson('/api/business/settings/profile', ['name' => 'Badminton City Doha'])->assertOk();

    expect($business->fresh()->name)->toBe('Badminton City Doha');
});

it('resolves an ownerless business from its owner id everywhere the legacy lookup did', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();

    expect(Business::forOwnerId($owner->id)?->id)->toBe($business->id)
        ->and(AccessScope::business($owner)?->id)->toBe($business->id)
        ->and(AccessScope::managedBusiness($owner)?->id)->toBe($business->id)
        ->and(\App\Support\PremiumPlan::businessFor($owner)?->id)->toBe($business->id);
});

/*
 * The Listings page filters its venue cards and rows by owner_user_id (the
 * dashboard sends the signed-in provider's owner id). For an ownerless business
 * the venues carry business_id and no owner key, so the page came back empty
 * while Bookings — scoped through AccessScope — still listed their bookings.
 */

it('lists an ownerless business\'s venues and listings when the dashboard filters by owner id', function () {
    [$owner, $business, $venues] = lsmOwnerlessBusinessWithOwner();
    $venue = $venues->first();
    $listing = \App\Models\Listing::create([
        'name' => 'Court A', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 50, 'capacity' => 4, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => '1'], 'max_bookings_per_slot' => 1,
    ]);
    $manager = lsmLegacyManagerUnder($owner);

    $venuesRes = $this->actingAs($manager)->getJson("/api/admin/venues/dashboard?owner_user_id={$owner->id}")->assertOk();
    expect(collect($venuesRes->json('data.locations'))->pluck('id')->map(fn ($v) => (int) $v)->all())
        ->toEqualCanonicalizing($venues->pluck('id')->all());

    $listingsRes = $this->actingAs($manager)->getJson("/api/admin/listings/dashboard?owner_user_id={$owner->id}")->assertOk();
    expect(collect($listingsRes->json('data.listings'))->pluck('id')->map(fn ($v) => (int) $v)->all())->toBe([$listing->id]);

    // Another owner's id must not widen the view.
    $other = User::factory()->create(['status' => User::ACTIVE]);
    $other->assignRole('provider_admin');
    $none = $this->actingAs($manager)->getJson("/api/admin/listings/dashboard?owner_user_id={$other->id}")->assertOk();
    expect($none->json('data.listings'))->toBe([]);
});

it('links a venue created by a member of an ownerless business to that business', function () {
    [$owner, $business] = lsmOwnerlessBusinessWithOwner();
    $manager = lsmLegacyManagerUnder($owner);

    $country = \Nnjeim\World\Models\Country::create([
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '974', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    $state = \Nnjeim\World\Models\State::create(['name' => 'Doha', 'country_id' => $country->id, 'country_code' => 'QAT']);
    $city = \Nnjeim\World\Models\City::create([
        'name' => 'Doha', 'country_id' => $country->id, 'country_code' => 'QAT', 'state_id' => $state->id, 'status' => 1,
    ]);

    $res = $this->actingAs($manager)->postJson('/api/admin/venues/dashboard', [
        'name' => 'New Hall', 'status' => 'active', 'country_id' => $country->id, 'city_id' => $city->id,
    ]);
    $res->assertOk();

    $venue = Venue::where('name', 'New Hall')->firstOrFail();
    expect((int) $venue->business_id)->toBe($business->id)
        ->and((int) $venue->owner_user_id)->toBe($owner->id)
        ->and(AccessScope::allowedVenueIds($owner->fresh()))->toContain($venue->id);
});
