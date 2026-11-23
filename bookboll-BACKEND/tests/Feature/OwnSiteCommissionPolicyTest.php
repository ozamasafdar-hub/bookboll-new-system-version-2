<?php

/**
 * The commission policy readmeAlamgir specifies, as its own acceptance table.
 *
 *   Marketplace booking -> the standard rate, on every plan.
 *   Merchant's own site -> the independently configured Free or eligible Premium website rate.
 *
 * Connecting payouts never changes the commission, and on the merchant's own
 * site the online deposit IS Bookboll's fee rather than the provider's no-show
 * protection.
 */

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\CommissionRate;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Support\CommissionPolicy as Policy;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A venue + listing owned by a business, at a given standard rate. */
function listingAt(float $standardPct, ?array $businessAttrs = null, ?array $subAttrs = null): array
{
    $owner = User::factory()->create();
    $venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = Listing::create([
        'name' => 'Court A', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => $standardPct,
        'payment_options' => [], 'max_bookings_per_slot' => 1,
    ]);

    $business = null;
    if ($businessAttrs !== null) {
        $business = Business::create(array_merge([
            'owner_id' => $owner->id, 'name' => 'Biz', 'username' => 'biz-'.uniqid(),
            'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
        ], $businessAttrs));

        if ($subAttrs !== null) {
            BusinessSubscription::create(array_merge([
                'business_id' => $business->id, 'plan' => 'premium', 'billing' => 'yearly',
                'status' => 'active', 'amount' => 588, 'currency' => 'USD',
                'current_period_end' => now()->addMonths(6),
            ], $subAttrs));
        }
    }

    return [$listing->fresh('venue'), $business?->fresh()];
}

/** A paid-Premium business, exactly as the policy requires. */
function paidPremium(float $standardPct): array
{
    return listingAt($standardPct, ['plan' => 'premium'], ['status' => 'active']);
}

it('charges the independent Free website rate on a booking, and keeps the deposit', function () {
    [$listing] = listingAt(20, ['plan' => 'free']);

    $q = Policy::quote($listing, Policy::CHANNEL_SITE, Money::toMinor(100, 'QAR'), 'QAR', 'deposit');

    // Deposit 15 retained by Bookboll, 85 on arrival.
    expect($q['effective_rate_pct'])->toBe(15.0)
        ->and($q['plan_eligible'])->toBeFalse()
        ->and(Money::toMajor($q['fee_minor'], 'QAR'))->toBe(15.0)
        ->and(Money::toMajor($q['due_now_minor'], 'QAR'))->toBe(15.0)
        ->and(Money::toMajor($q['arrival_balance_minor'], 'QAR'))->toBe(85.0);
});

it('uses the Premium website rate for a paid Premium merchant', function () {
    [$listing] = paidPremium(20);

    $q = Policy::quote($listing, Policy::CHANNEL_SITE, Money::toMinor(100, 'QAR'), 'QAR', 'deposit');

    // Deposit/fee 10, merchant share 90.
    expect($q['plan_eligible'])->toBeTrue()
        ->and($q['effective_rate_pct'])->toBe(10.0)
        ->and(Money::toMajor($q['fee_minor'], 'QAR'))->toBe(10.0)
        ->and(Money::toMajor($q['merchant_share_minor'], 'QAR'))->toBe(90.0);
});

it('preserves fractional configured website fees in minor units', function () {
    [$listing] = paidPremium(15);
    \App\Models\MarketSetting::where('scope', 'global')->update(['values' => ['site_premium' => 7.5]]);
    app(\App\Support\MarketSettings::class)->clear();

    $q = Policy::quote($listing, Policy::CHANNEL_SITE, Money::toMinor(100, 'QAR'), 'QAR', 'deposit');

    expect($q['effective_rate_pct'])->toBe(7.5)
        ->and(Money::toMajor($q['fee_minor'], 'QAR'))->toBe(7.5)
        ->and(Money::toMajor($q['merchant_share_minor'], 'QAR'))->toBe(92.5);
});

it('never discounts marketplace commission, on any plan', function () {
    [$free] = listingAt(20, ['plan' => 'free']);
    [$paid] = paidPremium(20);

    expect(Policy::effectiveRatePct($free, Policy::CHANNEL_MARKETPLACE))->toBe(20.0)
        ->and(Policy::effectiveRatePct($paid, Policy::CHANNEL_MARKETPLACE))->toBe(20.0);
});

it('treats an unknown channel as marketplace rather than handing out the discount', function () {
    [$listing] = paidPremium(20);

    expect(Policy::effectiveRatePct($listing, 'something-else'))->toBe(20.0);
});

it('refuses the discount to a trial, a past-due plan and a free plan', function (array $case) {
    [$plan, $subAttrs] = $case;
    [$listing] = listingAt(20, ['plan' => $plan], $subAttrs);

    expect(Policy::effectiveRatePct($listing, Policy::CHANNEL_SITE))->toBe(15.0);
})->with([
    'legacy trial'      => [['premium', ['status' => 'trialing', 'trial_ends_at' => now()->addDays(10)]]],
    'active but in trial window' => [['premium', ['status' => 'active', 'trial_ends_at' => now()->addDays(5)]]],
    'past due'          => [['premium', ['status' => 'past_due']]],
    'cancelled'         => [['premium', ['status' => 'canceled']]],
    'free plan'         => [['free', null]],
    'no subscription'   => [['premium', null]],
]);

it('keeps the discount for a cancellation scheduled at period end, and drops it after', function () {
    [$stillPaid] = listingAt(20, ['plan' => 'premium'],
        ['status' => 'active', 'cancel_at_period_end' => true, 'current_period_end' => now()->addMonth()]);
    expect(Policy::effectiveRatePct($stillPaid, Policy::CHANNEL_SITE))->toBe(10.0);

    [$ended] = listingAt(20, ['plan' => 'premium'],
        ['status' => 'active', 'cancel_at_period_end' => true, 'current_period_end' => now()->subDay()]);
    expect(Policy::effectiveRatePct($ended, Policy::CHANNEL_SITE))->toBe(15.0);
});

it('charges the whole price on full online, and credits only the merchant share', function () {
    [$listing] = paidPremium(20);

    $q = Policy::quote($listing, Policy::CHANNEL_SITE, Money::toMinor(100, 'QAR'), 'QAR', 'online');

    expect(Money::toMajor($q['due_now_minor'], 'QAR'))->toBe(100.0)
        ->and(Money::toMajor($q['fee_minor'], 'QAR'))->toBe(10.0)
        ->and(Money::toMajor($q['merchant_share_minor'], 'QAR'))->toBe(90.0)
        ->and($q['arrival_balance_minor'])->toBe(0);
});

it('reconciles fee, share and arrival balance against the total, at any rate', function (array $case) {
    [$total, $pct, $method] = $case;
    [$listing] = paidPremium(20);
    \App\Models\MarketSetting::where('scope', 'global')->update(['values' => ['site_premium' => $pct]]);
    app(\App\Support\MarketSettings::class)->clear();

    $minor = Money::toMinor($total, 'QAR');
    $q = Policy::quote($listing, Policy::CHANNEL_SITE, $minor, 'QAR', $method);

    expect($q['effective_rate_pct'])->toBe((float) $pct)
        ->and($q['fee_minor'] + $q['merchant_share_minor'])->toBe($minor)
        ->and($q['due_now_minor'] + $q['arrival_balance_minor'])->toBe($minor)
        ->and($q['fee_minor'])->toBeGreaterThanOrEqual(0)
        ->and($q['arrival_balance_minor'])->toBeGreaterThanOrEqual(0);
})->with([
    [[100, 10, 'deposit']], [[99.99, 7.5, 'deposit']], [[250.50, 12.5, 'online']],
    [[0, 10, 'deposit']], [[1, 7.5, 'deposit']], [[12345.67, 11.25, 'online']],
]);

it('falls back to the country rate when the listing has no override', function () {
    $owner = User::factory()->create();
    $venue = Venue::create([
        'name' => 'V', 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'country_id' => 179,
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    CommissionRate::create(['country_id' => 179, 'listing_type' => 'facility', 'rate_percent' => 30]);
    $listing = Listing::create([
        'name' => 'C', 'venue_id' => $venue->id, 'status' => 'active', 'base_price' => 100,
        'capacity' => 1, 'slot_duration' => 60, 'commission_rate' => null,
        'payment_options' => [], 'max_bookings_per_slot' => 1,
    ])->fresh('venue');

    expect(Policy::standardRatePct($listing))->toBe(30.0);
});
