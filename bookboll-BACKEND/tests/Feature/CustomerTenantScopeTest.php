<?php

/**
 * Customers are business-wise.
 *
 * There is no `customers.business_id`: a customer is a global `users` row with the
 * `customer` role, and which business they belong to is DERIVED — they booked at a
 * venue the business owns, or they subscribed to one of its programmes. The rule
 * lives in App\Support\AccessScope::applyCustomerScope().
 *
 * The capability middleware on /api/admin/* decides WHETHER a role may open
 * Customers; it never decided WHICH customers come back. Six read endpoints and
 * three writes used to ignore the scope entirely and serve the whole marketplace,
 * so these tests pin the ownership boundary on every one of them.
 */

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\BusinessCustomer;
use App\Models\ClassSubscription;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // User::$appends carries next_tier, which walks the tier ladder and null-derefs
    // when no tiers exist — so serialising any customer on an unseeded database
    // 500s. Nothing to do with scoping; the real database always has these rows.
    $this->seed(LoyaltyTierTableSeeder::class);

    // users.loyalty_tier_id is NOT NULL DEFAULT 1, and getLoyaltyTierAttribute()
    // returns null for an id that has no row — which next_tier then calls a method
    // on. InnoDB does not roll AUTO_INCREMENT back between RefreshDatabase tests, so
    // the seeded tiers are only 1..4 for the first test in the run; point every user
    // at the tier that actually exists instead of relying on that default.
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    $mk = function (string $email, string $role) use ($bronze): User {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create([
            'email'           => $email,
            'status'          => User::ACTIVE,
            'loyalty_tier_id' => $bronze,
        ]);
        $u->assignRole($role);

        return $u->fresh();
    };

    // Two unrelated businesses, plus one provider that owns nothing at all.
    $this->provA = $mk('prov-a@test.local', 'provider_admin');
    $this->provB = $mk('prov-b@test.local', 'provider_admin');
    $this->provC = $mk('prov-c@test.local', 'provider_admin');
    $this->super = $mk('super@test.local', 'super_admin');

    $venA = Venue::create(['name' => 'Venue A', 'owner_user_id' => $this->provA->id]);
    $venB = Venue::create(['name' => 'Venue B', 'owner_user_id' => $this->provB->id]);

    $this->booker   = $mk('booked-at-a@test.local', 'customer');
    $this->rival    = $mk('booked-at-b@test.local', 'customer');
    $this->member   = $mk('subscribed-to-a@test.local', 'customer');
    $this->stranger = $mk('no-relationship@test.local', 'customer');

    foreach ([[$this->booker, $venA], [$this->rival, $venB]] as [$cust, $ven]) {
        Booking::create([
            'user_id'  => $cust->id, 'venue_id' => $ven->id,
            'subtotal' => 10, 'total_price' => 10,
        ]);
    }

    $program = ClassProgram::create([
        'uuid'     => (string) Str::uuid(), 'name' => 'Program A',
        'venue_id' => $venA->id, 'mapped_owner_user_id' => $this->provA->id,
    ]);
    ClassSubscription::create([
        'uuid'           => (string) Str::uuid(), 'program_id' => $program->id,
        // Deliberately different case: the rule matches on LOWER(email).
        'customer_email' => 'Subscribed-To-A@test.local',
    ]);
});

/**
 * Customer emails one admin sees from a list endpoint. The test instance is passed
 * in rather than reached for with test(), matching the other feature tests here.
 */
function customersSeenBy($test, User $actor, string $url): array
{
    return collect(
        $test->actingAs($actor)->getJson($url)->json('data.customers.data') ?? []
    )->pluck('email')->sort()->values()->all();
}

/* ─────────────── the list is scoped to the caller's business ─────────────── */

/*
 * 'no-relationship@test.local' is nobody's customer — a marketplace signup who has
 * booked nowhere and joined nothing. A business sees only the people it has a
 * relationship with: booked with it, joined its programme, or added by its team.
 * The shared pool that briefly showed every such signup to every business
 * (2026-11-03, "no claim") was withdrawn on 2026-11-13: on live it put 12,000+
 * strangers in front of a business with 450 customers of its own.
 */

it('shows a provider its own bookers and programme members only', function () {
    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toBe(['booked-at-a@test.local', 'subscribed-to-a@test.local']);
});

it('does not show one provider another provider\'s customers', function () {
    expect(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->toBe(['booked-at-b@test.local']);
});

it('shows nothing to a provider that owns no venue and no programme', function () {
    expect(customersSeenBy($this, $this->provC, '/api/admin/customers?records_per_page=50'))
        ->toBe([]);
});

it('never shows a marketplace signup who has done nothing with any business', function () {
    foreach ([$this->provA, $this->provB, $this->provC] as $prov) {
        expect(customersSeenBy($this, $prov, '/api/admin/customers?records_per_page=50'))
            ->not->toContain('no-relationship@test.local');
    }
});

it('shows a hand-added signup to the business that added them and to nobody else', function () {
    $stranger = User::where('email', 'no-relationship@test.local')->firstOrFail();
    BusinessCustomer::link($this->provA->id, $stranger->id);

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('no-relationship@test.local')
        ->and(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->toBe(['booked-at-b@test.local']);
});

it('shows a signup only to the business they booked with', function () {
    $stranger = User::where('email', 'no-relationship@test.local')->firstOrFail();
    $venB     = Venue::where('name', 'Venue B')->firstOrFail();
    Booking::create(['user_id' => $stranger->id, 'venue_id' => $venB->id, 'subtotal' => 10, 'total_price' => 10]);

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toBe(['booked-at-a@test.local', 'subscribed-to-a@test.local'])
        ->and(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->toContain('no-relationship@test.local');
});

it('keeps a hand-added client that later books elsewhere visible to the business that added them', function () {
    // A's manual link survives the customer leaving the shared pool: the pivot
    // branch, not the unbooked branch, carries them for A from then on.
    $stranger = User::where('email', 'no-relationship@test.local')->firstOrFail();
    BusinessCustomer::link($this->provA->id, $stranger->id);
    $venB = Venue::where('name', 'Venue B')->firstOrFail();
    Booking::create(['user_id' => $stranger->id, 'venue_id' => $venB->id, 'subtotal' => 10, 'total_price' => 10]);

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('no-relationship@test.local')
        ->and(customersSeenBy($this, $this->provC, '/api/admin/customers?records_per_page=50'))
        ->toBe([]);
});

it('still shows a super admin the whole marketplace', function () {
    expect(customersSeenBy($this, $this->super, '/api/admin/customers?records_per_page=50'))
        ->toBe([
            'booked-at-a@test.local', 'booked-at-b@test.local',
            'no-relationship@test.local', 'subscribed-to-a@test.local',
        ]);
});

it('scopes the lightweight picker list the same way', function () {
    expect(customersSeenBy($this, $this->provA, '/api/admin/customers/list?records_per_page=50'))
        ->toBe(['booked-at-a@test.local', 'subscribed-to-a@test.local']);
});

it('accepts per_page as well as records_per_page on the picker list', function () {
    // The admin mobile app sends per_page; the dashboard sends records_per_page.
    // Both must size the page, and the cap keeps one request from pulling the base.
    $super = $this->super;

    $byRecords = $this->actingAs($super)->getJson('/api/admin/customers/list?records_per_page=1')->json('data.customers');
    expect($byRecords['per_page'])->toBe(1)->and($byRecords['data'])->toHaveCount(1);

    $byPerPage = $this->actingAs($super)->getJson('/api/admin/customers/list?per_page=2')->json('data.customers');
    expect($byPerPage['per_page'])->toBe(2)->and($byPerPage['data'])->toHaveCount(2);

    $capped = $this->actingAs($super)->getJson('/api/admin/customers/list?per_page=9999')->json('data.customers');
    expect($capped['per_page'])->toBe(250);

    $default = $this->actingAs($super)->getJson('/api/admin/customers/list')->json('data.customers');
    expect($default['per_page'])->toBe(15);
});

it('scopes the dashboard roster the same way', function () {
    expect(customersSeenBy($this, $this->provA, '/api/admin/customers/dashboard?records_per_page=50'))
        ->toBe(['booked-at-a@test.local', 'subscribed-to-a@test.local']);
});

it('keeps a search inside the caller\'s own customers', function () {
    // The rival matches the term too, but belongs to the other business.
    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50&search=booked-at'))
        ->toBe(['booked-at-a@test.local']);
});

/* ─────────────── reading one customer across the boundary ─────────────── */

it('serves a customer of the caller\'s own business', function () {
    $this->actingAs($this->provA)
        ->getJson("/api/admin/customers/{$this->booker->id}/profile")
        ->assertOk();
});

it('404s the profile of another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->getJson("/api/admin/customers/{$this->rival->id}/profile")
        ->assertNotFound();
});

it('404s the bookings of another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->getJson("/api/admin/customers/{$this->rival->id}/bookings")
        ->assertNotFound();
});

it('404s the CRM record of another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->getJson("/api/admin/customers/{$this->rival->id}/crm")
        ->assertNotFound();
});

/* ─────────────── writing across the boundary is refused ─────────────── */

it('will not let a provider block another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->putJson("/api/admin/customers/{$this->rival->id}/status", ['status' => 'blocked'])
        ->assertNotFound();

    expect($this->rival->fresh()->status)->toBe(User::ACTIVE);
});

it('will not let a provider block a super admin', function () {
    // The sharpest case: this endpoint took any user id, not just customers.
    $this->actingAs($this->provA)
        ->putJson("/api/admin/customers/{$this->super->id}/status", ['status' => 'blocked'])
        ->assertNotFound();

    expect($this->super->fresh()->status)->toBe(User::ACTIVE);
});

it('will not let a provider tag another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->putJson("/api/admin/customers/{$this->rival->id}/tags", ['tags' => ['vip']])
        ->assertNotFound();

    expect($this->rival->fresh()->tags)->toHaveCount(0);
});

it('will not let a provider delete another business\'s customer', function () {
    $this->actingAs($this->provA)
        ->deleteJson("/api/admin/customers/{$this->rival->id}")
        ->assertNotFound();

    expect($this->rival->fresh()->hasRole('customer'))->toBeTrue();
});

it('still lets a provider delete its own customer', function () {
    $this->actingAs($this->provA)
        ->deleteJson("/api/admin/customers/{$this->member->id}")
        ->assertOk();

    expect($this->member->fresh()->hasRole('customer'))->toBeFalse();
});

it('still lets a provider block its own customer', function () {
    $this->actingAs($this->provA)
        ->putJson("/api/admin/customers/{$this->booker->id}/status", ['status' => 'blocked'])
        ->assertOk();

    expect($this->booker->fresh()->status)->toBe(User::BLOCKED);
});

/* ─────────── the id-list form of the rule agrees with the query form ─────────── */

it('derives the same customers as the list, per business', function () {
    // The marketing composer and the customer picker narrow by customerIdsInScope().
    // If that disagreed with the list, a provider could email someone the list hides.
    expect(AccessScope::customerIdsInScope($this->provA))
        ->toEqualCanonicalizing([$this->booker->id, $this->member->id]);

    expect(AccessScope::customerIdsInScope($this->provB))
        ->toEqualCanonicalizing([$this->rival->id]);
});

it('leaves customerIdsInScope unrestricted (null) for a super admin', function () {
    expect(AccessScope::customerIdsInScope($this->super))->toBeNull();
});

it('puts nobody in scope for a provider that owns nothing', function () {
    // scopedProgramIds() used to build an empty where() in this case, matching EVERY
    // programme and pulling every subscriber's email into scope. Now it reaches
    // nobody — not other businesses' customers, and not unattached signups.
    expect(AccessScope::customerIdsInScope($this->provC))->toBe([]);
});

/* ─────────── a client the business adds by hand belongs to that business ─────────── */

it('keeps a hand-added client visible to the business that added them', function () {
    // The case the derived rule cannot see: no booking, no subscription.
    $created = $this->actingAs($this->provA)->postJson('/api/admin/customers', [
        'full_name' => 'Walk In Client',
        'email'     => 'walkin@test.local',
        'phone'     => '12345678',
        'status'    => 'active',
    ])->assertCreated();

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('walkin@test.local');

    // ...and is still editable, which used to 404 straight after creation.
    $this->actingAs($this->provA)
        ->putJson("/api/admin/customers/{$created->json('data.customer.id')}", ['phone' => '99999999'])
        ->assertOk();
});

it('records both the pivot row and created_by when a business adds a client', function () {
    $this->actingAs($this->provA)->postJson('/api/admin/customers', [
        'full_name' => 'Walk In Client',
        'email'     => 'walkin@test.local',
        'status'    => 'active',
    ])->assertCreated();

    $client = User::where('email', 'walkin@test.local')->firstOrFail();

    expect($client->created_by)->toBe($this->provA->id);
    expect(BusinessCustomer::where('user_id', $client->id)
        ->where('owner_user_id', $this->provA->id)
        ->where('source', BusinessCustomer::SOURCE_ADDED)
        ->exists())->toBeTrue();
});

it('never shares a hand-added client with other businesses, before or after they book', function () {
    // A client A typed in is A's: B neither lists them nor can open their profile,
    // and A's first booking with them changes nothing about that.
    $this->actingAs($this->provA)->postJson('/api/admin/customers', [
        'full_name' => 'Walk In Client',
        'email'     => 'walkin@test.local',
        'status'    => 'active',
    ])->assertCreated();

    $client = User::where('email', 'walkin@test.local')->firstOrFail();
    expect(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->not->toContain('walkin@test.local');

    $venA = Venue::where('name', 'Venue A')->firstOrFail();
    Booking::create(['user_id' => $client->id, 'venue_id' => $venA->id, 'subtotal' => 10, 'total_price' => 10]);

    expect(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->not->toContain('walkin@test.local')
        ->and(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('walkin@test.local');

    $this->actingAs($this->provB)
        ->getJson("/api/admin/customers/{$client->id}/profile")
        ->assertNotFound();
});

it('lets a provider with no venues still see the clients it added', function () {
    // provC owns no venue and no programme, so before the pivot it could never see
    // anyone -- including someone it had just created itself.
    $this->actingAs($this->provC)->postJson('/api/admin/customers', [
        'full_name' => 'Pre Launch Client',
        'email'     => 'prelaunch@test.local',
        'status'    => 'active',
    ])->assertCreated();

    expect(customersSeenBy($this, $this->provC, '/api/admin/customers?records_per_page=50'))
        ->toBe(['prelaunch@test.local']);
});

it('links a client added through the register endpoint too', function () {
    $this->actingAs($this->provA)->postJson('/api/admin/customers/register', [
        'name'  => 'Registered Client',
        'email' => 'registered@test.local',
    ])->assertSuccessful();

    $client = User::where('email', 'registered@test.local')->firstOrFail();

    expect(BusinessCustomer::where('user_id', $client->id)
        ->where('owner_user_id', $this->provA->id)->exists())->toBeTrue();

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('registered@test.local');
});

it('attributes a client added by staff to the owner, not the staff member', function () {
    Role::findOrCreate('staff', 'web');
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');
    $staff  = User::factory()->create([
        'email'           => 'staff-a@test.local',
        'status'          => User::ACTIVE,
        'loyalty_tier_id' => $bronze,
        'owner_user_id'   => $this->provA->id,
    ]);
    $staff->assignRole('staff');

    $this->actingAs($staff->fresh())->postJson('/api/admin/customers', [
        'full_name' => 'Staff Added Client',
        'email'     => 'staff-added@test.local',
        'status'    => 'active',
    ])->assertCreated();

    $client = User::where('email', 'staff-added@test.local')->firstOrFail();

    // The business owns the client, even though a staff member typed it in.
    expect(BusinessCustomer::where('user_id', $client->id)
        ->where('owner_user_id', $this->provA->id)->exists())->toBeTrue();

    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->toContain('staff-added@test.local');
});

it('does not link anything when a super admin adds a customer', function () {
    // A platform role has no business of its own, so there is nothing to attribute.
    // Such a customer belongs to nobody: no business lists them until they book
    // with it, join its programme, or its team adds them.
    $this->actingAs($this->super)->postJson('/api/admin/customers', [
        'full_name' => 'Platform Added',
        'email'     => 'platform-added@test.local',
        'status'    => 'active',
    ])->assertCreated();

    $client = User::where('email', 'platform-added@test.local')->firstOrFail();

    expect(BusinessCustomer::where('user_id', $client->id)->exists())->toBeFalse();
    expect(customersSeenBy($this, $this->provA, '/api/admin/customers?records_per_page=50'))
        ->not->toContain('platform-added@test.local')
        ->and(customersSeenBy($this, $this->provB, '/api/admin/customers?records_per_page=50'))
        ->not->toContain('platform-added@test.local')
        ->and(customersSeenBy($this, $this->super, '/api/admin/customers?records_per_page=50'))
        ->toContain('platform-added@test.local');
});
