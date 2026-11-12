<?php

/**
 * Loyalty is per country (readmeAlamgir "Alamgir: country balances and ranks").
 *
 * Earn rate, redeem rate, minimum, cap and expiry all live on a country's
 * programme, so a global total is not spendable anywhere in particular. The
 * endpoint refused to be shown as a country balance precisely because it never
 * said which country it was.
 */

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const QATAR = 179;
const PAKISTAN = 167;

// The country must exist for the endpoint's `exists:countries,id` guard — the
// point of which is that a balance is only ever reported for a real country.
beforeEach(function () {
    foreach ([[QATAR, 'Qatar', 'QA', 'QAT'], [PAKISTAN, 'Pakistan', 'PK', 'PAK']] as [$id, $name, $iso2, $iso3]) {
        \Nnjeim\World\Models\Country::firstOrCreate(['id' => $id], [
            'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
            'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
        ]);
    }
});

function award(User $u, int $points, ?int $country, string $type = 'credit'): LoyaltyPoint
{
    return LoyaltyPoint::create([
        'user_id' => $u->id, 'points' => $points, 'type' => $type,
        'country_id' => $country, 'reason' => 'test',
    ]);
}

function programFor(int $countryId, array $over = []): LoyaltyProgram
{
    return LoyaltyProgram::create(array_merge([
        'country_id' => $countryId, 'name' => 'Programme', 'earn_rate' => 1,
        'redeem_rate' => 100, 'redeem_value' => 1, 'min_redeem' => 100,
        'max_redeem_pct' => 50, 'expiry_months' => 12, 'is_active' => true,
    ], $over));
}

it('requires a country, because a balance without one means nothing', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/loyalty')
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_id');
});

it('keeps two countries completely separate', function () {
    $u = User::factory()->create();
    award($u, 500, QATAR);
    award($u, 300, PAKISTAN);

    $qa = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();
    $pk = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.PAKISTAN)->assertOk();

    expect($qa->json('data.balance'))->toBe(500)
        ->and($pk->json('data.balance'))->toBe(300);
});

it('always says which country it answered for, even at zero', function () {
    $res = $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();

    expect($res->json('data.country_id'))->toBe(QATAR)
        ->and($res->json('data.balance'))->toBe(0);
});

it('never folds legacy untagged points into a country balance', function () {
    $u = User::factory()->create();
    award($u, 1000, null);        // earned before the ledger was scoped
    award($u, 200, QATAR);

    $res = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();

    // Spendable in Qatar is only what was earned in Qatar...
    expect($res->json('data.balance'))->toBe(200)
        // ...and the orphaned points are reported, not hidden and not spendable.
        ->and($res->json('data.unscoped_points'))->toBe(1000);
});

it('subtracts spends only from the country they were spent in', function () {
    $u = User::factory()->create();
    award($u, 500, QATAR);
    award($u, 500, PAKISTAN);
    award($u, 200, QATAR, 'debit');

    $qa = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();
    $pk = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.PAKISTAN)->assertOk();

    expect($qa->json('data.balance'))->toBe(300)
        ->and($qa->json('data.lifetime_earned'))->toBe(500)
        ->and($pk->json('data.balance'))->toBe(500);
});

it('reads rules and tiers from the requested country programme only', function () {
    $qaProgram = programFor(QATAR, ['redeem_rate' => 100, 'min_redeem' => 100]);
    $pkProgram = programFor(PAKISTAN, ['redeem_rate' => 250, 'min_redeem' => 500]);
    LoyaltyTier::create([
        'program_id' => $qaProgram->id, 'name' => 'Silver', 'slug' => 'silver',
        'min_points' => 1000, 'multiplier' => 1.5, 'order' => 1, 'is_active' => true,
    ]);
    LoyaltyTier::create([
        'program_id' => $pkProgram->id, 'name' => 'Gold', 'slug' => 'gold',
        'min_points' => 9999, 'multiplier' => 3, 'order' => 1, 'is_active' => true,
    ]);

    $res = $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();

    expect((float) $res->json('data.program.redeem_rate'))->toBe(100.0)
        ->and($res->json('data.program.min_redeem'))->toBe(100)
        ->and($res->json('data.tiers.0.slug'))->toBe('silver')
        ->and((float) $res->json('data.tiers.0.multiplier'))->toBe(1.5)
        // Pakistan's Gold must not appear under Qatar.
        ->and(collect($res->json('data.tiers'))->pluck('slug')->all())->not->toContain('gold');
});

it('uses global rewards for a country without an override', function () {
    $res = $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/loyalty?country_id='.PAKISTAN)->assertOk();

    expect($res->json('data.program.earn_rate'))->toBe(1)
        ->and($res->json('data.tiers'))->toBe([]);
});

it('advertises checkout redemption now that venue checkout enforces the quote', function () {
    $res = $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();

    // This asserted false while the quote endpoint existed but nothing honoured
    // it — deploying the website is not what makes redemption work. Venue
    // checkout now validates the quote against the server's own prices,
    // reserves its points at payment and consumes them on confirmation, and
    // the payments that cannot honour a quote refuse one outright. So the
    // capability no longer promises anything that silently fails.
    expect($res->json('data.redemption_checkout_v1'))->toBeTrue();
});

it('shows the ledger for that country only, with spends signed negative', function () {
    $u = User::factory()->create();
    award($u, 50, QATAR);
    award($u, 20, QATAR, 'debit');
    award($u, 99, PAKISTAN);

    $res = $this->actingAs($u)->getJson('/api/customer/loyalty?country_id='.QATAR)->assertOk();
    $points = collect($res->json('data.ledger'))->pluck('points')->all();

    expect($res->json('data.ledger'))->toHaveCount(2)
        ->and($points)->toContain(50)
        ->and($points)->toContain(-20)
        ->and($points)->not->toContain(99);
});

it('reads the customer from the token and ignores any id in the request', function () {
    $mine = User::factory()->create();
    $other = User::factory()->create();
    award($mine, 10, QATAR);
    award($other, 7777, QATAR);

    $res = $this->actingAs($mine)
        ->getJson('/api/customer/loyalty?country_id='.QATAR.'&customer_id='.$other->id.'&user_id='.$other->id)
        ->assertOk();

    expect($res->json('data.balance'))->toBe(10);
});
