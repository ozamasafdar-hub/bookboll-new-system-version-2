<?php

/**
 * invoices:prune keeps the newest invoice of every booking and removes the rest.
 *
 * Every payment change regenerates the invoice and nothing removed the old
 * PDFs; the command is the housekeeping for that. The one rule that must never
 * break: the newest invoice of a booking — the one everything reads — survives
 * every option.
 */

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('public'));

function pruneBooking(User $user): Booking
{
    $venue = Venue::create([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE, 'operator_type' => 'bookboll',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active', 'base_price' => 100,
        'capacity' => 2, 'slot_duration' => 1, 'payment_options' => ['cash_on_arrival' => '1'], 'max_bookings_per_slot' => 1,
    ]);

    return Booking::create([
        'user_id' => $user->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'confirmed', 'payment_status' => 'fully_paid',
    ]);
}

/** N invoices for a booking, oldest first, each with a file on disk. */
function invoicesFor(Booking $booking, int $n, bool $withFiles = true): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $path = "invoices/invoice_INV_2026_{$booking->id}_{$i}.pdf";
        if ($withFiles) {
            Storage::disk('public')->put($path, str_repeat('x', 100 * $i));
        }
        $inv = Invoice::create([
            'user_id' => $booking->user_id, 'booking_id' => $booking->id, 'path' => $path,
            'invoice_number' => sprintf('INV_2026_%05d', $i), 'status' => $i === $n ? 'generated' : 're_generated',
        ]);
        // Timestamps are not fillable; backdate so --older-than has something to bite on.
        $inv->forceFill(['created_at' => now()->subDays(2 * ($n - $i)), 'updated_at' => now()->subDays(2 * ($n - $i))])->saveQuietly();
        $out[] = $inv->fresh();
    }

    return $out;
}

it('only reports without --delete', function () {
    $booking = pruneBooking(User::factory()->create());
    invoicesFor($booking, 3);

    $this->artisan('invoices:prune')
        ->expectsOutputToContain('2 superseded invoice(s) across 1 booking(s)')
        ->expectsOutputToContain('Re-run with')
        ->assertSuccessful();

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(3);
    expect(Storage::disk('public')->files('invoices'))->toHaveCount(3);
});

it('deletes every invoice but the newest, files and rows', function () {
    $booking = pruneBooking(User::factory()->create());
    [$oldest, $middle, $newest] = invoicesFor($booking, 3);

    $this->artisan('invoices:prune --delete --no-interaction')->assertSuccessful();

    expect(Invoice::where('booking_id', $booking->id)->pluck('id')->all())->toBe([$newest->id]);
    expect($booking->fresh()->latestInvoice->id)->toBe($newest->id);
    Storage::disk('public')->assertExists($newest->path);
    Storage::disk('public')->assertMissing($oldest->path);
    Storage::disk('public')->assertMissing($middle->path);
});

it('keeps as many as --keep says, newest first', function () {
    $booking = pruneBooking(User::factory()->create());
    [$a, $b, $c, $d] = invoicesFor($booking, 4);

    $this->artisan('invoices:prune --keep=2 --delete --no-interaction')->assertSuccessful();

    expect(Invoice::where('booking_id', $booking->id)->orderBy('id')->pluck('id')->all())->toBe([$c->id, $d->id]);
});

it('leaves a booking with a single invoice alone', function () {
    $booking = pruneBooking(User::factory()->create());
    invoicesFor($booking, 1);

    $this->artisan('invoices:prune --delete --no-interaction')
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

it('restricts to the bookings named', function () {
    $user = User::factory()->create();
    $a = pruneBooking($user);
    $b = pruneBooking($user);
    invoicesFor($a, 3);
    invoicesFor($b, 3);

    $this->artisan("invoices:prune --booking={$a->id} --delete --no-interaction")->assertSuccessful();

    expect(Invoice::where('booking_id', $a->id)->count())->toBe(1)
        ->and(Invoice::where('booking_id', $b->id)->count())->toBe(3);
});

it('with --older-than only removes superseded rows past that age', function () {
    $booking = pruneBooking(User::factory()->create());
    [$old, $recent, $newest] = invoicesFor($booking, 3);   // created 4, 2, 0 days ago

    $this->artisan('invoices:prune --older-than=3 --delete --no-interaction')->assertSuccessful();

    $left = Invoice::where('booking_id', $booking->id)->orderBy('id')->pluck('id')->all();
    expect($left)->toBe([$recent->id, $newest->id]);
});

it('with --missing-files drops rows whose PDF is gone, but never the newest', function () {
    $booking = pruneBooking(User::factory()->create());
    [$old, $newest] = invoicesFor($booking, 2, withFiles: false);   // nothing on disk at all

    $this->artisan('invoices:prune --keep=5 --missing-files --delete --no-interaction')->assertSuccessful();

    // keep=5 means nothing is "superseded"; only the missing-file rule applies,
    // and it spares the newest even though its file is missing too.
    expect(Invoice::where('booking_id', $booking->id)->pluck('id')->all())->toBe([$newest->id]);
});

it('with --orphan-files removes PDFs no row points to', function () {
    $booking = pruneBooking(User::factory()->create());
    invoicesFor($booking, 1);
    Storage::disk('public')->put('invoices/invoice_stray_1.pdf', 'stray');
    Storage::disk('public')->put('invoices/invoice_stray_2.pdf', 'stray');

    $this->artisan('invoices:prune --orphan-files --delete --no-interaction')
        ->expectsOutputToContain('2 orphan PDF(s)')
        ->assertSuccessful();

    expect(Storage::disk('public')->files('invoices'))->toHaveCount(1);
});

it('never touches per-payment invoices', function () {
    $booking = pruneBooking(User::factory()->create());
    invoicesFor($booking, 2);
    $payment = new \App\Models\Payment([
        'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => PaymentType::FULL,
        'amount' => 100, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CASH, 'payment_id' => 'PAY_RCT_1',
    ]);
    $payment->saveQuietly();   // a fixture, not a payment event
    $perPayment = Invoice::create([
        'user_id' => $booking->user_id, 'booking_id' => $booking->id, 'payment_id' => $payment->id,
        'path' => 'invoices/keep_me.pdf', 'invoice_number' => 'RCT_1', 'status' => 'generated',
    ]);

    $this->artisan('invoices:prune --delete --no-interaction')->assertSuccessful();

    expect(Invoice::find($perPayment->id))->not->toBeNull();
});
