<?php

/**
 * What the booking RECORDS, and what the wallet is credited.
 *
 * readmeAlamgir: "Store the final channel, standard/effective rate, plan
 * eligibility, fee, merchant share, deposit and arrival balance with the
 * booking/payment snapshot. Preserve historical amounts when plans or
 * commission settings change" and "do not recalculate historical fees using a
 * merchant's later plan."
 */

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Support\CommissionPolicy as Policy;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function premiumListing(float $standardPct = 20): array
{
    $owner = User::factory()->create();
    $venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'currency_override' => 'QAR',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $business = Business::create([
        'owner_id' => $owner->id, 'name' => 'Biz', 'username' => 'biz-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'premium',
    ]);
    BusinessSubscription::create([
        'business_id' => $business->id, 'plan' => 'premium', 'billing' => 'yearly',
        'status' => 'active', 'amount' => 588, 'currency' => 'USD',
        'current_period_end' => now()->addMonths(6),
    ]);
    $listing = Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => $standardPct, 'payment_options' => [], 'max_bookings_per_slot' => 1,
    ])->fresh('venue');

    return [$listing, $business];
}

it('freezes the channel and both rates onto the booking', function () {
    [$listing] = premiumListing(20);

    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $listing->venue_id, 'listing_id' => $listing->id,
        'date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'pending',
    ] + [
        'commission_channel' => 'site',
        'commission_standard_pct' => 20,
        'commission_effective_pct' => 10,
        'commission_plan_eligible' => true,
        'commission_fee_minor' => Money::toMinor(10, 'QAR'),
        'merchant_share_minor' => Money::toMinor(90, 'QAR'),
        'commission_currency' => 'QAR',
    ]);

    expect($booking->commission_channel)->toBe('site')
        ->and((float) $booking->commission_standard_pct)->toBe(20.0)
        ->and((float) $booking->commission_effective_pct)->toBe(10.0)
        ->and($booking->commission_fee_minor + $booking->merchant_share_minor)
            ->toBe(Money::toMinor(100, 'QAR'));
});

it('does not rewrite a confirmed booking when the plan later lapses', function () {
    [$listing, $business] = premiumListing(20);

    // Booked while Premium: own-site rate is 10%.
    $quoted = Policy::quote($listing, Policy::CHANNEL_SITE, Money::toMinor(100, 'QAR'), 'QAR', 'deposit');
    expect($quoted['effective_rate_pct'])->toBe(10.0);

    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $listing->venue_id, 'listing_id' => $listing->id,
        'date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'confirmed',
        'commission_channel' => 'site',
        'commission_effective_pct' => $quoted['effective_rate_pct'],
        'commission_fee_minor' => $quoted['fee_minor'],
        'merchant_share_minor' => $quoted['merchant_share_minor'],
        'commission_currency' => 'QAR',
    ]);

    // The plan lapses afterwards.
    $business->subscriptions()->update(['status' => 'canceled']);
    $business->update(['plan' => 'free']);

    // Losing Premium changes features, not the fee on new or existing bookings.
    expect(Policy::effectiveRatePct($listing->fresh('venue'), Policy::CHANNEL_SITE))->toBe(10.0);
    expect((float) $booking->fresh()->commission_effective_pct)->toBe(10.0)
        ->and(Money::toMajor($booking->fresh()->commission_fee_minor, 'QAR'))->toBe(10.0);
});

it('settles an own-site booking at its own rate, not the marketplace rate', function () {
    [$listing] = premiumListing(20);

    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $listing->venue_id, 'listing_id' => $listing->id,
        'date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'confirmed',
        'commission_channel' => 'site', 'commission_effective_pct' => 10,
        'commission_currency' => 'QAR',
    ]);

    $svc = app(App\Services\StripeService::class);
    $fn = (new ReflectionClass($svc))->getMethod('bookingCommissionPct');
    $fn->setAccessible(true);

    // The marketplace rate here is 20. Settling at that would take twice the fee.
    expect($fn->invoke($svc, $booking, $listing))->toBe(10.0);
});

it('falls back to the live marketplace rate for bookings predating the snapshot', function () {
    [$listing] = premiumListing(20);

    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $listing->venue_id, 'listing_id' => $listing->id,
        'date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'confirmed',
    ]);   // no snapshot columns at all

    $svc = app(App\Services\StripeService::class);
    $fn = (new ReflectionClass($svc))->getMethod('bookingCommissionPct');
    $fn->setAccessible(true);

    // Exactly how it was settled before the snapshot existed.
    expect($fn->invoke($svc, $booking, $listing))->toBe(20.0);
});
