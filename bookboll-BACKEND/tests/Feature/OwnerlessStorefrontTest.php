<?php

/**
 * The white-label storefront for a business created from the admin "Add
 * business" form.
 *
 * Such a business is OWNERLESS: `businesses.owner_id` is null and its owner is
 * attached by membership, so its venues carry `venues.business_id` and no owner
 * key. Every public surface keyed the site off `businesses.owner_id` — which
 * read as 0 — so badmintoncity.bookboll.com resolved its brand, reported every
 * module as empty, and served a blank site over four venues and ten listings.
 */

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'provider_admin', 'facility_admin', 'customer'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

/**
 * A published, ownerless business with one active venue and one bookable listing.
 *
 * @return array{0: User, 1: Business, 2: Venue, 3: Listing}
 */
function storefrontBusiness(string $slug, bool $published = true): array
{
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $owner->assignRole('provider_admin');

    $business = Business::create([
        'owner_id' => null, 'name' => 'Badminton City', 'username' => $slug,
        'status' => Business::STATUS_ACTIVE, 'brand_enabled' => $published,
    ]);

    BusinessMembership::create([
        'business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'provider_admin',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE, 'version' => 1,
    ]);

    $venue = Venue::create(['name' => 'Madinat Khalifa', 'status' => Venue::ACTIVE]);
    $venue->forceFill(['business_id' => $business->id])->save();

    $listing = Listing::create([
        'name' => '9v9 Full Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 150, 'capacity' => 1, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => '1'], 'max_bookings_per_slot' => 1,
        'show_on_provider_site' => true, 'show_on_customer' => true,
    ]);

    return [$owner->fresh(), $business->fresh(), $venue->fresh(), $listing->fresh()];
}

it('hands the storefront a real owner id instead of 0', function () {
    [$owner, $business] = storefrontBusiness('badmintoncity');

    $brand = $this->getJson('/api/marketplace/brand?slug=badmintoncity')->assertOk()->json('data.brand');

    // The storefront calls setBrandScope() with this and keys every catalogue
    // request off it; "0" matched no venue anywhere.
    expect($brand['ownerUserId'])->toBe((string) $owner->id)
        ->and($brand['businessId'])->toBe((string) $business->id)
        ->and($brand['enabled'])->toBeTrue();
});

it('reports the venues module as present for an ownerless business', function () {
    storefrontBusiness('badmintoncity');

    $brand = $this->getJson('/api/marketplace/brand?slug=badmintoncity')->assertOk()->json('data.brand');

    expect($brand['modules']['venues'])->toBeTrue();
});

it('serves the business\'s own locations on its subdomain', function () {
    [, , $venue] = storefrontBusiness('badmintoncity');

    $locations = $this->getJson('/api/marketplace/brand-locations?brand_slug=badmintoncity')
        ->assertOk()->json('data.locations');

    expect(collect($locations)->pluck('id')->map(fn ($v) => (int) $v)->all())->toBe([$venue->id]);
});

it('lists the business\'s venues in the catalogue scoped to its brand', function () {
    [, , $venue] = storefrontBusiness('badmintoncity');

    $res = $this->getJson('/api/marketplace/venues?brand_slug=badmintoncity')->assertOk();
    $ids = collect($res->json('data.venues') ?? $res->json('data.facilities') ?? [])
        ->pluck('id')->map(fn ($v) => (int) $v)->all();

    expect($ids)->toContain($venue->id);
});

it('serves one of its venues and listings by id on its own subdomain', function () {
    [, , $venue, $listing] = storefrontBusiness('badmintoncity');

    $facility = $this->getJson("/api/marketplace/venues/{$venue->id}?brand_slug=badmintoncity")
        ->assertOk()->json('data.facility');

    expect($facility)->not->toBeNull()
        ->and((int) $facility['location']['id'])->toBe($venue->id);

    expect($this->getJson("/api/marketplace/listings/{$listing->id}?brand_slug=badmintoncity")
        ->assertOk()->json('data.detail'))->not->toBeNull();
});

it('never serves another business\'s venue on this brand', function () {
    storefrontBusiness('badmintoncity');
    [, , $rivalVenue, $rivalListing] = storefrontBusiness('rivalclub');

    $locations = $this->getJson('/api/marketplace/brand-locations?brand_slug=badmintoncity')
        ->assertOk()->json('data.locations');
    expect(collect($locations)->pluck('id')->map(fn ($v) => (int) $v)->all())
        ->not->toContain($rivalVenue->id);

    $this->getJson("/api/marketplace/venues/{$rivalVenue->id}?brand_slug=badmintoncity")
        ->assertOk()->assertJsonPath('data.facility', null);

    $this->getJson("/api/marketplace/listings/{$rivalListing->id}?brand_slug=badmintoncity")
        ->assertOk()->assertJsonPath('data.detail', null);
});

it('reports the regions an ownerless business serves', function () {
    [, , $venue] = storefrontBusiness('badmintoncity');

    $country = \Nnjeim\World\Models\Country::create([
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '974', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    $state = \Nnjeim\World\Models\State::create(['name' => 'Doha', 'country_id' => $country->id, 'country_code' => 'QAT']);
    $city = \Nnjeim\World\Models\City::create([
        'name' => 'Doha', 'country_id' => $country->id, 'country_code' => 'QAT',
        'state_id' => $state->id, 'status' => 1,
    ]);
    $venue->forceFill(['city_id' => $city->id, 'country_id' => $country->id])->save();

    $res = $this->getJson('/api/marketplace/served-regions?brand_slug=badmintoncity')->assertOk();

    expect(collect($res->json('data.cities'))->pluck('id')->map(fn ($v) => (int) $v)->all())
        ->toContain($city->id);
});

it('still keeps an unpublished brand off its subdomain', function () {
    storefrontBusiness('draftclub', published: false);

    expect($this->getJson('/api/marketplace/brand?slug=draftclub')->assertOk()->json('data.brand'))
        ->toBeNull();
});
