<?php

/**
 * What a deposit booking actually charges.
 *
 * Two separate defects met here, both of them money:
 *
 *  1. On the MARKETPLACE the deposit is Bookboll's platform fee, but it was read from
 *     payment_options.deposit.percentage — a snapshot the dashboard writes only when a
 *     listing is saved. Raising Pakistan's rate from 15% to 50% changed nothing for
 *     listings already live: they kept collecting 15%, while the Countries page told
 *     the admin "facility bookings use this rate live".
 *
 *  2. On a provider's OWN white-label site the storefront quoted the provider's
 *     site_deposit_percentage while the server charged the marketplace percentage,
 *     because `surface` never reached the API — createCustomerBookingsMulti dropped
 *     it before building the payload.
 *
 * These pin the resolver both surfaces now share.
 */

use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Services\MarketplaceCatalogService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

function geoCountryFor(string $name, string $iso2, string $iso3): Country
{
    return Country::create([
        'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
}

/** A provider-owned venue + listing in $country, with the given payment_options. */
function depositListing(Country $country, array $paymentOptions, array $listingAttrs = []): Listing
{
    $state = State::create(['name' => $country->name.' State', 'country_id' => $country->id, 'country_code' => $country->iso3]);
    $city = City::create([
        'name' => $country->name.' City', 'country_id' => $country->id,
        'country_code' => $country->iso3, 'state_id' => $state->id, 'status' => 1,
    ]);
    $owner = User::factory()->create();

    $venue = Venue::create([
        'name' => 'Venue', 'owner_user_id' => $owner->id,
        'country_id' => $country->id, 'city_id' => $city->id, 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    return Listing::create(array_merge([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 4, 'slot_duration' => 1,
        'payment_options' => $paymentOptions, 'max_bookings_per_slot' => 1,
    ], $listingAttrs));
}

/** What PaymentService would take online now, for one booking of $total. */
function charged(Listing $listing, float $total, string $surface, int $bookings = 1): float
{
    $m = new ReflectionMethod(PaymentService::class, 'depositToCharge');
    $m->setAccessible(true);

    return (float) $m->invoke(app(PaymentService::class), $listing, $total, $bookings, $surface);
}

/** What the storefront is QUOTED for the marketplace. */
function quotedMarketplacePct(Listing $listing): float
{
    $m = new ReflectionMethod(MarketplaceCatalogService::class, 'paymentOptions');
    $m->setAccessible(true);

    return (float) $m->invoke(app(MarketplaceCatalogService::class), $listing)['deposit_percentage'];
}

const SNAPSHOT_15 = [
    'cash_on_arrival' => false, 'deposit_enabled' => true, 'deposit_amount' => 0,
    'deposit_percentage' => 15, 'online_payment_enabled' => false,
    'deposit' => ['enabled' => true, 'amount' => 0, 'percentage' => 15],
    'site_deposit_enabled' => true, 'site_deposit_percentage' => 10,
    'site_online_enabled' => false, 'site_cash_enabled' => false,
];

beforeEach(function () {
    $this->pakistan = geoCountryFor('Pakistan', 'PK', 'PAK');
});

it('quotes and charges configured city website fees instead of stale listing payment options', function () {
    $listing = depositListing($this->pakistan, SNAPSHOT_15, ['commission_rate' => 20])->fresh('venue');
    \App\Models\MarketSetting::updateOrCreate(['scope' => 'city', 'scope_id' => $listing->venue->city_id], ['values' => ['site_free' => 8.25]]);
    app(\App\Support\MarketSettings::class)->clear();
    $catalog = new ReflectionMethod(MarketplaceCatalogService::class, 'paymentOptions');
    expect($catalog->invoke(app(MarketplaceCatalogService::class), $listing)['site_deposit_percentage'])->toBe(8.25)
        ->and(charged($listing, 200, 'site'))->toBe(16.5)
        ->and(charged($listing, 200, 'marketplace'))->toBe(40.0);
});

it('charges the country rate set today, not the one stored when the listing was saved', function () {
    // Exactly the reported case: listing saved at 15%, Pakistan since moved to 50%.
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    $listing = depositListing($this->pakistan, SNAPSHOT_15);

    expect(charged($listing, 100, 'marketplace'))->toBe(50.0);
});

it('quotes the storefront the same number it will charge', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    $listing = depositListing($this->pakistan, SNAPSHOT_15);

    // A quote of 15 next to a charge of 50 is the worst of both worlds.
    expect(quotedMarketplacePct($listing))->toBe(50.0)
        ->and(charged($listing, 100, 'marketplace'))->toBe(50.0);
});

it('lets a per-listing override beat the country rate', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    $listing = depositListing($this->pakistan, SNAPSHOT_15, ['commission_rate' => 20]);

    expect(charged($listing, 100, 'marketplace'))->toBe(20.0)
        ->and(quotedMarketplacePct($listing))->toBe(20.0);
});

/* ───────────────────────────────────────────────────────────────────────────
 * Own-site deposits were REDEFINED by readmeAlamgir's commission policy.
 *
 * They used to be the provider's own no-show protection, set by the provider,
 * with Bookboll taking nothing. They are now Bookboll's commission: the
 * standard rate, halved for a paid Premium merchant, retained by Bookboll and
 * included in the quoted price. The five tests that pinned the old rule are
 * rewritten below to pin the new one — the behaviour they described is gone on
 * purpose, so asserting it would now be asserting the bug.
 * ─────────────────────────────────────────────────────────────────────────── */

it('charges Bookboll commission on the own site, not the provider stored figure', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    // The listing still carries the provider's old 10% in site_deposit_percentage.
    $listing = depositListing($this->pakistan, SNAPSHOT_15);

    // On a Free plan the own-site rate IS the standard rate. The stored 10% is
    // a record of a preview, never the authority.
    expect(charged($listing, 100, 'site'))->toBe(50.0);
});

it('ignores the stored own-site percentage entirely, so a merchant cannot set their own fee', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);

    // Same listing, three different stored percentages, one charged number.
    foreach ([0, 20, 99] as $stored) {
        $listing = depositListing($this->pakistan, array_merge(SNAPSHOT_15, [
            'site_deposit_percentage' => $stored,
        ]));

        expect(charged($listing, 100, 'site'))->toBe(50.0);
    }
});

it('keeps a fixed deposit amount on the marketplace, but never on the own site', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    $listing = depositListing($this->pakistan, array_merge(SNAPSHOT_15, [
        'deposit_amount' => 25,
        'deposit' => ['enabled' => true, 'amount' => 25, 'percentage' => 15],
    ]));

    // Marketplace: unchanged. A fixed amount is the provider's no-show
    // protection and is charged per booking.
    expect(charged($listing, 350, 'marketplace', 3))->toBe(75.0);

    // Own site: the deposit is Bookboll's fee, so a stored amount must not win —
    // otherwise a merchant sets their own commission by typing a number.
    expect(charged($listing, 350, 'site', 3))->toBe(175.0);   // 50% of 350
});

it('rounds the deposit on the real money instead of flooring it', function () {
    \App\Models\CommissionRate::create([
        'country_id' => $this->pakistan->id, 'listing_type' => 'facility', 'rate_percent' => 50,
    ]);
    $listing = depositListing($this->pakistan, SNAPSHOT_15);

    // 50% of 67 is 33.50. This used to floor() in major units and charge 33,
    // which is the same defect readmeAlamgir names for a 7.5% fee billed as 7.
    // The storefront's paymentSplit() was moved off Math.floor in the same
    // release so both ends still show one number.
    expect(charged($listing, 67, 'marketplace'))->toBe(33.5);
});

it('takes nothing when the commission itself is zero', function () {
    // Zero commission is a real configuration and must stay zero — never
    // silently replaced by a default rate.
    $listing = depositListing($this->pakistan, SNAPSHOT_15, ['commission_rate' => 0]);

    expect(charged($listing, 100, 'site'))->toBe(0.0)
        ->and(charged($listing, 100, 'marketplace'))->toBe(0.0);
});

it('accepts surface on the booking payload', function () {
    $rules = app(\App\Services\BookingService::class)->customerBookingValidationRules();

    expect($rules)->toHaveKey('surface')
        ->and($rules['surface'])->toContain('marketplace')->toContain('site');
});
