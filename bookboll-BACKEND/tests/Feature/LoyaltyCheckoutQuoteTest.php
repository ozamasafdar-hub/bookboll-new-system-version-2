<?php

/**
 * POST /customer/loyalty/checkout-quote (readmeAlamgir §2 of the redemption
 * contract).
 *
 * "Quote requests must not debit/reserve points or create bookings." It is a
 * priced offer, bound to the account, country, currency and the inputs it was
 * priced from, with an expiry.
 */

use App\Models\Listing;
use App\Models\LoyaltyCheckoutQuote;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const QA = 179;

beforeEach(function () {
    \Nnjeim\World\Models\Country::firstOrCreate(['id' => QA], [
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    LoyaltyProgram::create([
        'country_id' => QA, 'name' => 'Rewards', 'earn_rate' => 1, 'redeem_rate' => 100,
        'redeem_value' => 1, 'min_redeem' => 100, 'max_redeem_pct' => 100,
        'expiry_months' => 12, 'is_active' => true,
    ]);
});

function qaListing(float $price = 100, float $commission = 15): Listing
{
    $owner = User::factory()->create();
    $venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'country_id' => QA,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    return Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => $price, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => $commission, 'payment_options' => [], 'max_bookings_per_slot' => 1,
    ])->fresh('venue');
}

function customerWith(int $points): User
{
    $u = User::factory()->create();
    LoyaltyPoint::create([
        'user_id' => $u->id, 'points' => $points, 'type' => 'credit',
        'country_id' => QA, 'reason' => 'test',
    ]);

    return $u;
}

function askQuote(User $u, Listing $l, array $over = [])
{
    return test()->actingAs($u)->postJson('/api/customer/loyalty/checkout-quote', array_merge([
        'kind' => 'listing', 'country_id' => QA, 'currency' => 'QAR',
        'checkout' => ['listing_id' => $l->id, 'slots' => [['date'=>now()->addDay()->toDateString(), 'start_time' => '10:00','end_time'=>'11:00']]],
    ], $over));
}

it('offers at most Bookboll commission and spends nothing doing so', function () {
    $u = customerWith(10000);
    $l = qaListing(100, 15);

    $res = askQuote($u, $l)->assertOk();

    expect($res->json('data.discount'))->toBe(15)
        ->and($res->json('data.points'))->toBe(1500)
        ->and($res->json('data.bookboll_share.commission'))->toBe(15)
        ->and($res->json('data.order_total_before'))->toBe(100)
        ->and($res->json('data.quote_id'))->toStartWith('lq_');

    // Nothing was debited and nothing reserved — it is an offer, not a purchase.
    expect(LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->count())->toBe(0)
        ->and(LoyaltyCheckoutQuote::first()->reserved_at)->toBeNull()
        ->and(LoyaltyCheckoutQuote::first()->consumed_at)->toBeNull();
});

it('offers nothing, as a priced answer rather than an error, at zero commission', function () {
    $res = askQuote(customerWith(10000), qaListing(100, 0))->assertOk();

    expect($res->json('data.points'))->toBe(0)
        ->and($res->json('data.quote_id'))->toBeNull()
        ->and($res->json('data.reason'))->toContain('no Bookboll fee');
});

it('offers nothing when the customer has too few points', function () {
    $res = askQuote(customerWith(50), qaListing(100, 15))->assertOk();

    expect($res->json('data.points'))->toBe(0);
});

it('uses only the balance earned in the requested country', function () {
    $u = User::factory()->create();
    // Plenty of points, but all in another country.
    LoyaltyPoint::create([
        'user_id' => $u->id, 'points' => 50000, 'type' => 'credit',
        'country_id' => 167, 'reason' => 'elsewhere',
    ]);

    $res = askQuote($u, qaListing(100, 15))->assertOk();

    expect($res->json('data.points'))->toBe(0);
});

it('refuses a currency that is not the one the booking is charged in', function () {
    askQuote(customerWith(10000), qaListing(), ['currency' => 'PKR'])->assertStatus(422);
});

it('refuses a kind the server cannot price', function () {
    // membership, host_game and host_tournament are priced now; anything else
    // is still refused rather than guessed at.
    foreach (['subscription', 'event', 'anything', ''] as $kind) {
        askQuote(customerWith(10000), qaListing(), ['kind' => $kind])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
    }
});

it('refuses a priceable kind without the thing it is meant to price', function () {
    $customer = customerWith(10000);

    // A membership names a programme. Without one there is nothing to price,
    // and falling back to the listing in the payload would price the wrong
    // order entirely.
    askQuote($customer, qaListing(), ['kind' => 'membership'])->assertStatus(422);
});

it('requires authentication', function () {
    $l = qaListing();
    $this->postJson('/api/customer/loyalty/checkout-quote', [
        'kind' => 'listing', 'country_id' => QA, 'currency' => 'QAR',
        'checkout' => ['listing_id' => $l->id],
    ])->assertStatus(401);
});

it('prices from the server and ignores money the browser sends', function () {
    $u = customerWith(100000);
    $l = qaListing(100, 15);

    // A hostile client claiming a huge order and commission.
    $res = askQuote($u, $l, ['checkout' => [
        'listing_id' => $l->id, 'slots' => [['date'=>now()->addDay()->toDateString(), 'start_time' => '10:00','end_time'=>'11:00']],
        'total' => 100000, 'commission' => 99999, 'discount' => 50000,
    ]])->assertOk();

    expect($res->json('data.order_total_before'))->toBe(100)
        ->and($res->json('data.discount'))->toBe(15);
});

it('binds the quote to this account, country and order', function () {
    $u = customerWith(10000);
    askQuote($u, qaListing(100, 15))->assertOk();

    $q = LoyaltyCheckoutQuote::first();

    expect((int) $q->user_id)->toBe($u->id)
        ->and((int) $q->country_id)->toBe(QA)
        ->and($q->currency)->toBe('QAR')
        ->and($q->inputs_hash)->not->toBeEmpty()
        ->and($q->expires_at->isFuture())->toBeTrue()
        // Usable for its own account/order...
        ->and($q->usableFor($u->id, QA, 'QAR', $q->inputs_hash))->toBeTrue()
        // ...and for nobody else, no other country, no changed order.
        ->and($q->usableFor($u->id + 1, QA, 'QAR', $q->inputs_hash))->toBeFalse()
        ->and($q->usableFor($u->id, 167, 'QAR', $q->inputs_hash))->toBeFalse()
        ->and($q->usableFor($u->id, QA, 'PKR', $q->inputs_hash))->toBeFalse()
        ->and($q->usableFor($u->id, QA, 'QAR', 'changed-order'))->toBeFalse();
});

it('refuses an expired quote', function () {
    $u = customerWith(10000);
    askQuote($u, qaListing(100, 15))->assertOk();

    $q = LoyaltyCheckoutQuote::first();
    $q->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($q->fresh()->usableFor($u->id, QA, 'QAR', $q->inputs_hash))->toBeFalse();
});

it('refuses a quote that has already been spent', function () {
    $u = customerWith(10000);
    askQuote($u, qaListing(100, 15))->assertOk();

    $q = LoyaltyCheckoutQuote::first();
    $q->forceFill(['consumed_at' => now()])->save();

    expect($q->fresh()->usableFor($u->id, QA, 'QAR', $q->inputs_hash))->toBeFalse();
});
