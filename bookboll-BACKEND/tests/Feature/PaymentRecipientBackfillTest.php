<?php

/**
 * payments:backfill-recipient
 *
 * PaymentService's stats card counts `recipient IS NULL` towards "to Bookboll".
 * That is CORRECT for Bookboll's own venues — its staff took the cash — and on
 * this platform almost every blank-recipient cash receipt is one of those. So
 * the thing these tests actually guard is restraint: the command must label the
 * provider's cash without moving Bookboll's own money into the wrong column.
 */

use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backfillVenue(array $over = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'owner_user_id' => null, 'business_id' => null,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ], $over));
}

function backfillPayment(Venue $v, array $over = []): Payment
{
    // These are historical rows from before financial snapshots/recipient
    // stamping. Bypass today's creation observers to preserve that fixture.
    $b = Booking::withoutEvents(fn () => Booking::create([
        'user_id' => User::factory()->create()->id, 'venue_id' => $v->id,
        'date' => '2026-01-15', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'completed',
        'payment_status' => 'fully_paid',
    ]));

    return Payment::withoutEvents(fn () => Payment::create(array_merge([
        'booking_id' => $b->id, 'user_id' => $b->user_id, 'payment_id' => 'p-'.uniqid(),
        'type' => 'remaining', 'method' => 'cash', 'status' => 'paid',
        'amount' => 80, 'currency' => 'QAR', 'recipient' => null,
    ], $over)));
}

function providerVenueWithBusiness(): array
{
    $business = Business::create([
        'owner_id' => null, 'name' => 'Riverside Sports', 'username' => 'riverside',
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    // business_id is not in Venue's $fillable — create() would drop it silently
    // and the venue would look like Bookboll's, which is what this test denies.
    $venue = backfillVenue(['operator_type' => 'provider']);
    $venue->forceFill(['business_id' => $business->id])->save();

    return [$venue->fresh(), $business];
}

// ── What it must leave alone ───────────────────────────────────────────────

it('does not touch a Bookboll venue, whose blank recipient is already right', function () {
    // The whole reason this is not a blanket UPDATE. Bookboll's staff took this
    // cash; labelling it as a provider's would understate platform revenue.
    $payment = backfillPayment(backfillVenue());

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBeNull();
});

it('never overwrites a recipient somebody already wrote', function () {
    [$venue] = providerVenueWithBusiness();
    $payment = backfillPayment($venue, ['recipient' => '@handwritten']);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBe('@handwritten');
});

it('leaves a paid cash deposit to the transfer reconciliation', function () {
    // A paid deposit is what reconciliation re-labels as Bookboll's FEE. Naming
    // a provider on it here would contradict that.
    [$venue] = providerVenueWithBusiness();
    $payment = backfillPayment($venue, ['type' => 'deposit']);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBeNull();
});

it('leaves online money alone, which carries its own recipient rows', function () {
    [$venue] = providerVenueWithBusiness();
    $payment = backfillPayment($venue, ['method' => 'online']);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBeNull();
});

it('leaves an unpaid balance alone — nobody has collected it yet', function () {
    [$venue] = providerVenueWithBusiness();
    $payment = backfillPayment($venue, ['status' => 'pending']);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBeNull();
});

// ── What it must do ────────────────────────────────────────────────────────

it('labels provider cash with the business handle', function () {
    [$venue, $business] = providerVenueWithBusiness();
    $payment = backfillPayment($venue);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBe('@'.$business->username);
});

it('names a venue that has an owner but no business row', function () {
    // Still not Bookboll's money, so it gets a non-null label either way.
    $owner = User::factory()->create(['name' => 'Imran Sports']);
    $payment = backfillPayment(backfillVenue([
        'operator_type' => 'provider', 'owner_user_id' => $owner->id,
    ]));

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->not->toBeNull()
        ->and($payment->fresh()->recipient)->not->toBe('@bookboll');
});

// ── How it behaves when run ────────────────────────────────────────────────

it('writes nothing without --commit', function () {
    [$venue] = providerVenueWithBusiness();
    $payment = backfillPayment($venue);

    $this->artisan('payments:backfill-recipient')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBeNull();
});

it('is safe to run twice', function () {
    [$venue, $business] = providerVenueWithBusiness();
    $payment = backfillPayment($venue);

    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);
    $this->artisan('payments:backfill-recipient --commit')->assertExitCode(0);

    expect($payment->fresh()->recipient)->toBe('@'.$business->username);
});

it('reports an empty queue rather than failing', function () {
    $this->artisan('payments:backfill-recipient --commit')
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(0);
});
