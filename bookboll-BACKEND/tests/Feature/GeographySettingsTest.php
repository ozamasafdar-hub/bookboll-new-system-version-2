<?php

use App\Models\Business;
use App\Models\CommissionRate;
use App\Models\Listing;
use App\Models\LoyaltyProgram;
use App\Models\User;
use App\Models\Venue;
use App\Services\CustomerGameService;
use App\Services\GeographyService;
use App\Services\ProgramSaveService;
use App\Support\CityModules;
use App\Support\CommissionPolicy;
use App\Support\LoyaltyCountry;
use App\Support\MarketSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    foreach (['super_admin', 'country_admin', 'provider_admin'] as $role) Role::findOrCreate($role, 'web');
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin)->withHeader('from', 'admin-web');
    $this->country = Country::create(['name' => 'Zulu QA', 'iso2' => 'ZQ', 'iso3' => 'ZQA', 'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1]);
    $this->other = Country::create(['name' => 'Alpha QA', 'iso2' => 'AQ', 'iso3' => 'AQA', 'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1]);
    $state = State::create(['name' => 'QA state', 'country_id' => $this->country->id, 'country_code' => 'ZQA']);
    $this->city = City::create(['name' => 'QA city', 'country_id' => $this->country->id, 'country_code' => 'ZQA', 'state_id' => $state->id, 'status' => 1]);
    $this->venue = Venue::create(['name' => 'QA venue', 'country_id' => $this->country->id, 'city_id' => $this->city->id,
        'status' => 'active', 'maps_url' => 'https://maps.google.com/?q=0,0', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1]);
    $this->listing = Listing::create(['name' => 'QA court', 'venue_id' => $this->venue->id, 'status' => 'active', 'base_price' => 100,
        'capacity' => 10, 'slot_duration' => 60, 'commission_rate' => null, 'payment_options' => [], 'max_bookings_per_slot' => 1]);
});

function marketValues(array $overrides = []): array { return array_replace(array_fill_keys(array_keys(MarketSettings::DEFAULTS), null), $overrides); }
function saveMarket(string $scope, int $id, array $values) { return test()->putJson("/api/admin/geography/settings/$scope/$id", ['values' => $values]); }

it('filters onboarded countries and fee overrides across the full directory before pagination', function () {
    $owner = User::factory()->create();
    Business::create(['name' => 'Business only country', 'username' => 'business-only-qa', 'owner_id' => $owner->id, 'country_id' => $this->other->id]);
    $this->getJson('/api/admin/geography/countries?view=onboarded&records_per_page=1')->assertOk()
        ->assertJsonPath('data.pagination.total', 2)->assertJsonPath('data.countries.0.id', $this->country->id);
    $this->getJson('/api/admin/geography/countries?view=onboarded&records_per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.countries.0.id', $this->other->id);
    saveMarket('country', $this->other->id, marketValues(['site_free' => 0]))->assertOk();
    $this->getJson('/api/admin/geography/countries?view=overrides')->assertOk()
        ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.countries.0.id', $this->other->id);
    saveMarket('country', $this->other->id, marketValues())->assertOk();
    $this->getJson('/api/admin/geography/countries?view=overrides')->assertOk()->assertJsonPath('data.pagination.total', 0);
    saveMarket('country', $this->country->id, marketValues(['facility' => 12]))->assertOk();
    $this->getJson('/api/admin/geography/countries?view=overrides')->assertOk()
        ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.countries.0.id', $this->country->id);
});

it('applies independent Free and Premium website fees through global country city and reset', function () {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'Website QA', 'username' => 'website-qa', 'owner_id' => $owner->id, 'plan' => 'premium']);
    $sub = \App\Models\BusinessSubscription::create(['business_id' => $business->id, 'plan' => 'premium', 'billing' => 'yearly',
        'status' => 'active', 'amount' => 588, 'currency' => 'USD', 'current_period_end' => now()->addMonth()]);
    // Explicit business ownership works even before an owner is attached to the venue.
    $this->venue->forceFill(['business_id' => $business->id])->save();
    $this->listing->update(['commission_rate' => 24]);
    $rate = fn () => CommissionPolicy::effectiveRatePct($this->listing->fresh('venue'), 'site');
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['site_free' => 12, 'site_premium' => 6]))->assertOk();
    expect($rate())->toBe(6.0);
    $this->getJson('/api/admin/reference-data')->assertOk()
        ->assertJsonPath('data.venues.0.business_plan.own_site_commission_discount_eligible', true);
    saveMarket('country', $this->country->id, marketValues(['site_free' => 9, 'site_premium' => 3]))->assertOk();
    expect($rate())->toBe(3.0);
    saveMarket('city', $this->city->id, marketValues(['site_free' => 7, 'site_premium' => 0]))->assertOk();
    expect($rate())->toBe(0.0)
        ->and(CommissionPolicy::effectiveRatePct($this->listing->fresh('venue'), 'marketplace'))->toBe(24.0);
    $this->getJson('/api/admin/geography/settings/city/'.$this->city->id)->assertOk()
        ->assertJsonPath('data.settings.effective.site_premium', 0)
        ->assertJsonPath('data.settings.sources.site_premium', 'city');
    foreach (['past_due', 'trialing', 'canceled'] as $status) {
        $sub->update(['status' => $status]);
        expect($rate())->toBe(7.0);
    }
    $sub->update(['status' => 'active']);
    saveMarket('city', $this->city->id, marketValues())->assertOk();
    expect($rate())->toBe(3.0);
    saveMarket('country', $this->country->id, marketValues())->assertOk();
    expect($rate())->toBe(6.0);
    saveMarket('global', 0, MarketSettings::DEFAULTS)->assertOk();
    expect($rate())->toBe(12.0); // Automatic half of the listing's 24%, not the global 15%.
    $business->update(['plan' => 'free']);
    expect($rate())->toBe(24.0);
});

it('charges the configured website deposit and full payment split while preserving recorded bookings', function () {
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['site_free' => 8.5]))->assertOk();
    $listing = $this->listing->fresh('venue');
    $deposit = CommissionPolicy::quote($listing, 'site', 20000, 'QAR', 'deposit');
    $full = CommissionPolicy::quote($listing, 'site', 20000, 'QAR', 'online');
    expect($deposit['fee_minor'])->toBe(1700)->and($deposit['due_now_minor'])->toBe(1700)
        ->and($deposit['arrival_balance_minor'])->toBe(18300)
        ->and($full['due_now_minor'])->toBe(20000)->and($full['merchant_share_minor'])->toBe(18300);
    $booking = \App\Models\Booking::create(['user_id' => $this->admin->id, 'venue_id' => $this->venue->id, 'listing_id' => $listing->id,
        'date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'subtotal' => 200, 'total_price' => 200,
        'status' => 'confirmed', 'commission_channel' => 'site', 'commission_effective_pct' => $deposit['effective_rate_pct'],
        'commission_fee_minor' => $deposit['fee_minor'], 'merchant_share_minor' => $deposit['merchant_share_minor'], 'commission_currency' => 'QAR']);
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['site_free' => 4]))->assertOk();
    $settleRate = new ReflectionMethod(\App\Services\StripeService::class, 'bookingCommissionPct');
    expect($settleRate->invoke(app(\App\Services\StripeService::class), $booking->fresh(), $listing))->toBe(8.5)
        ->and($booking->fresh()->commission_fee_minor)->toBe(1700)
        ->and(CommissionPolicy::quote($listing, 'site', 20000, 'QAR', 'deposit')['fee_minor'])->toBe(800);
});

it('preserves website overrides from older editors and rejects invalid fee percentages', function () {
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['site_free' => 8, 'site_premium' => 2]))->assertOk();
    $oldPayload = array_diff_key(MarketSettings::DEFAULTS, array_flip(MarketSettings::SITE_KEYS));
    saveMarket('global', 0, $oldPayload)->assertOk()->assertJsonPath('data.settings.effective.site_free', 8)
        ->assertJsonPath('data.settings.effective.site_premium', 2);
    foreach ([-1, 101, 'invalid'] as $value) {
        saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['site_free' => $value]))->assertUnprocessable();
        saveMarket('city', $this->city->id, marketValues(['site_premium' => $value]))->assertUnprocessable();
    }
    $this->getJson('/api/admin/reference-data')->assertOk()
        ->assertJsonFragment(['website_fees' => ['site_free' => 8, 'site_premium' => 2]]);
});

it('round trips global country and city settings with explicit zero and false values', function () {
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['facility' => 22, 'module_listings' => false]))->assertOk();
    expect(CommissionPolicy::standardRatePct($this->listing->fresh('venue')))->toBe(22.0)
        ->and(CityModules::enabledForVenue($this->venue->id, CityModules::LISTINGS))->toBeFalse();
    saveMarket('country', $this->country->id, marketValues(['facility' => 12, 'module_listings' => true]))->assertOk();
    saveMarket('city', $this->city->id, marketValues(['facility' => 0, 'module_listings' => false]))->assertOk();
    expect(CommissionPolicy::standardRatePct($this->listing->fresh('venue')))->toBe(0.0)
        ->and(CityModules::enabledForVenue($this->venue->id, CityModules::LISTINGS))->toBeFalse();
    $this->getJson('/api/admin/geography/cities?country_id='.$this->country->id)->assertOk()
        ->assertJsonPath('data.cities.0.settings.sources.facility', 'city')
        ->assertJsonPath('data.cities.0.module_listings', false);
    saveMarket('city', $this->city->id, marketValues())->assertOk();
    expect(CommissionPolicy::standardRatePct($this->listing->fresh('venue')))->toBe(12.0)
        ->and(CityModules::enabledForVenue($this->venue->id, CityModules::LISTINGS))->toBeTrue();
    saveMarket('country', $this->country->id, marketValues())->assertOk();
    expect(CommissionPolicy::standardRatePct($this->listing->fresh('venue')))->toBe(22.0);
});

it('keeps listing overrides and already quoted fees independent of default changes', function () {
    $quote = CommissionPolicy::quote($this->listing->fresh('venue'), 'marketplace', 10000, 'QAR', 'deposit');
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['facility' => 30]))->assertOk();
    expect($quote['fee_minor'])->toBe(1500);
    $this->listing->update(['commission_rate' => 7]);
    expect(CommissionPolicy::standardRatePct($this->listing->fresh('venue')))->toBe(7.0);
});

it('uses city and global event rates in the host quote calculation', function () {
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['event' => 11]))->assertOk();
    $method = new ReflectionMethod(CustomerGameService::class, 'seatFeePct');
    expect($method->invoke(app(CustomerGameService::class), null, false, $this->country->id))->toBe(11.0);
    saveMarket('city', $this->city->id, marketValues(['event' => 4]))->assertOk();
    expect($method->invoke(app(CustomerGameService::class), $this->listing->fresh('venue')))->toBe(4.0);
});

it('enforces the matchmaking switch for new events without changing existing content', function () {
    saveMarket('city', $this->city->id, marketValues(['module_matchmaking' => false]))->assertOk();
    $response = app(CustomerGameService::class)->create($this->admin, ['listing_id' => $this->listing->id]);
    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'])->toContain('disabled');
    expect($this->listing->fresh()->status)->toBe('active');
});

it('preserves configured low and zero membership rates rather than forcing twenty percent', function () {
    $method = new ReflectionMethod(ProgramSaveService::class, 'buildAttributes');
    foreach ([0, 5, 10] as $rate) {
        $attrs = $method->invoke(app(ProgramSaveService::class), ['name' => 'QA program', 'commission_percentage' => $rate], null);
        expect($attrs['commission_percentage'])->toBe((float) $rate);
    }
});

it('sorts by venues then distinct businesses before paginating including city-only venue geography', function () {
    $owner = User::factory()->create();
    $business = Business::create(['name' => 'QA operator', 'username' => 'qa-geography', 'owner_id' => $owner->id, 'country_id' => $this->country->id]);
    $this->venue->forceFill(['business_id' => $business->id, 'owner_user_id' => $owner->id])->save();
    Venue::create(['name' => 'City-only QA venue', 'city_id' => $this->city->id, 'owner_user_id' => $owner->id, 'status' => 'active',
        'maps_url' => 'https://maps.google.com/?q=0,0', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1]);
    $this->getJson('/api/admin/geography/countries?records_per_page=1')->assertOk()
        ->assertJsonPath('data.countries.0.id', $this->country->id)
        ->assertJsonPath('data.countries.0.venues_count', 2)
        ->assertJsonPath('data.countries.0.businesses_count', 1);
    $this->getJson('/api/admin/geography/countries?sort=name&search=Alpha')->assertOk()
        ->assertJsonPath('data.countries.0.id', $this->other->id);
});

it('rejects malformed and out-of-range rates atomically', function () {
    saveMarket('global', 0, array_replace(MarketSettings::DEFAULTS, ['facility' => 101, 'event' => 2]))->assertStatus(422);
    expect(app(MarketSettings::class)->rate('event'))->toBe(10.0);
    saveMarket('city', $this->city->id, ['facility' => 10])->assertStatus(422);
});

it('blocks providers and country admins from global settings and other jurisdictions', function () {
    $staff = User::factory()->create(['country_id' => $this->country->id]);
    $staff->assignRole('country_admin');
    $this->actingAs($staff);
    saveMarket('country', $this->country->id, marketValues(['facility' => 8]))->assertOk();
    saveMarket('country', $this->other->id, marketValues(['facility' => 8]))->assertForbidden();
    saveMarket('global', 0, MarketSettings::DEFAULTS)->assertForbidden();
    $this->patchJson('/api/admin/geography/countries/'.$this->other->id.'/status', ['status' => 'inactive'])->assertForbidden();
    $provider = User::factory()->create(); $provider->assignRole('provider_admin'); $this->actingAs($provider);
    saveMarket('country', $this->country->id, marketValues())->assertForbidden();
});

it('updates single and bulk availability and refuses empty bulk selections', function () {
    $this->patchJson('/api/admin/geography/countries/'.$this->country->id.'/status', ['status' => 'inactive'])->assertOk();
    $this->patchJson('/api/admin/geography/cities/'.$this->city->id.'/status', ['status' => 'inactive'])->assertOk();
    $this->postJson('/api/admin/geography/cities/bulk-status', ['country_id' => $this->country->id, 'status' => 'active', 'city_ids' => []])->assertStatus(422);
    expect((int) $this->city->fresh()->status)->toBe(0);
    $this->postJson('/api/admin/geography/cities/bulk-status', ['country_id' => $this->country->id, 'status' => 'active', 'city_ids' => [$this->city->id]])->assertOk();
    expect((int) $this->city->fresh()->status)->toBe(1);
});

it('saves global rewards and country overrides with an explicit disabled state and reset', function () {
    $defaults = array_merge(LoyaltyProgram::defaults(), ['country_id' => null, 'earn_rate' => 2, 'redeem_rate' => 50,
        'tiers' => [['name' => 'QA first tier', 'threshold' => 0, 'multiplier' => 1, 'color' => '#000000', 'bg_color' => '#ffffff']]]);
    $this->putJson('/api/admin/geography/loyalty-programs', $defaults)->assertOk();
    expect(LoyaltyCountry::program($this->country->id)->redeem_rate)->toBe(50)
        ->and(LoyaltyCountry::tiers(LoyaltyCountry::program($this->country->id))[0]['name'])->toBe('QA first tier');
    $this->putJson('/api/admin/geography/loyalty-programs', array_merge($defaults, ['country_id' => $this->country->id, 'is_active' => false]))->assertOk();
    expect(LoyaltyCountry::program($this->country->id))->toBeNull();
    $this->putJson('/api/admin/geography/loyalty-programs', ['country_id' => $this->country->id, 'use_global' => true])->assertOk();
    expect(LoyaltyCountry::program($this->country->id)->redeem_rate)->toBe(50);
    $this->getJson('/api/admin/geography/loyalty-programs')->assertOk()->assertJsonPath('data.defaults.earn_rate', 2);
});

function rewardsBooking($test, array $attributes = []): \App\Models\Booking {
    $customer = User::factory()->create();
    return \App\Models\Booking::create(array_replace([
        'booking_id' => 'QA-'.\Illuminate\Support\Str::uuid(), 'user_id' => $customer->id,
        'created_by' => $customer->id, 'venue_id' => $test->venue->id, 'listing_id' => $test->listing->id,
        'origin' => 'bookboll_web', 'commission_channel' => 'marketplace', 'total_price' => 100,
        'subtotal' => 100, 'date' => now()->addDay()->toDateString(), 'start_time' => '12:00', 'end_time' => '13:00',
        'payment_method' => 'cash_on_arrival', 'payment_status' => 'pending', 'status' => 'pending',
    ], $attributes));
}

it('earns from the country rate and tier instead of fixed listing points and freezes the promise', function () {
    $this->listing->update(['loyalty_points' => 999]);
    $program = LoyaltyProgram::create(array_merge(LoyaltyProgram::defaults(), ['country_id' => $this->country->id, 'earn_rate' => 2, 'expiry_months' => 3]));
    \App\Models\LoyaltyTier::create(['program_id' => $program->id, 'name' => 'QA tier', 'slug' => 'qa-tier', 'min_points' => 0, 'multiplier' => 1.5, 'is_active' => true, 'order' => 0]);
    $booking = rewardsBooking($this, ['total_price' => 80]);
    expect($booking->loyalty_points_earned)->toBe(240)->and((float) $booking->loyalty_policy['earn_rate'])->toBe(2.0);
    $program->update(['earn_rate' => 10, 'expiry_months' => 12]);
    $booking->user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id);
    $credit = $booking->user->loyaltyPoints()->first();
    expect($credit->points)->toBe(240)->and($credit->expires_at->toDateString())->toBe(now()->addMonthsNoOverflow(3)->toDateString());
    expect($booking->fresh()->loyalty_points_earned)->toBe(240);
});

it('inherits global earn rates but does not reward staff site skipped or event bookings', function () {
    LoyaltyProgram::create(array_merge(LoyaltyProgram::defaults(), ['earn_rate' => 3]));
    expect(rewardsBooking($this)->loyalty_points_earned)->toBe(300);
    foreach ([['origin' => 'manual_admin'], ['commission_channel' => 'site'], ['payment_method' => 'payment_skipped'], ['origin' => 'customer_game']] as $attributes) {
        $booking = rewardsBooking($this, $attributes);
        expect($booking->loyalty_points_earned)->toBe(0);
        $booking->user->addLoyaltyPoints(999, $booking->id);
        expect($booking->user->loyaltyPoints()->count())->toBe(0);
    }
    LoyaltyProgram::create(array_merge(LoyaltyProgram::defaults(), ['country_id' => $this->country->id, 'is_active' => false]));
    expect(rewardsBooking($this)->loyalty_points_earned)->toBe(0);
});

it('expires only unspent rewards and keeps legacy points and lifetime tiers intact', function () {
    $user = User::factory()->create();
    $add = fn ($points, $type, $expiry = null) => \App\Models\LoyaltyPoint::create([
        'user_id' => $user->id, 'country_id' => $this->country->id, 'points' => $points, 'type' => $type,
        'reason' => 'QA expiry', 'expires_at' => $expiry,
    ]);
    $add(50, 'credit'); // historical non-expiring balance
    $add(100, 'credit', now()->addDay());
    $add(60, 'debit'); // uses the expiring lot first
    expect(LoyaltyCountry::balance($user->id, $this->country->id)['balance'])->toBe(90);
    $this->travel(2)->days();
    expect(LoyaltyCountry::balance($user->id, $this->country->id)['balance'])->toBe(50)
        ->and(LoyaltyCountry::balance($user->id, $this->country->id)['lifetime_earned'])->toBe(150);
    $this->travelBack();
});
