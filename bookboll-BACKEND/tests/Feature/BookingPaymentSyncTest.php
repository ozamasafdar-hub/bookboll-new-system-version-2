<?php

/**
 * One place keeps a booking in line with its payments.
 *
 * A merchant accepted a balance in cash and the invoice kept saying "remaining
 * due". Every path that marked a payment paid re-did the sync by hand and each
 * forgot a different step: dashboard collect never regenerated the invoice,
 * mobile mark-paid only when fully paid, mark-received only when a box was
 * ticked. PaymentObserver now raises PaymentStatusChanged on every Eloquent
 * payment save, and SyncBookingAfterPaymentChange does the whole job once.
 */

use App\Events\PaymentStatusChanged;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingConfirmedUserNotification;
use App\Support\BookingPaymentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Notification::fake();
});

function syncBooking(User $user, string $status, string $paymentStatus, float $total = 200): Booking
{
    $venue = Venue::create([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE, 'operator_type' => 'bookboll',
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = Listing::create([
        'name' => 'Indoor Padel Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => $total, 'capacity' => 4, 'slot_duration' => 2,
        'payment_options' => ['cash_on_arrival' => '1'], 'max_bookings_per_slot' => 1,
    ]);
    $booking = Booking::create([
        'user_id' => $user->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'date' => now()->addDays(3)->toDateString(), 'start_time' => '19:00', 'end_time' => '21:00',
        'subtotal' => $total, 'total_price' => $total, 'status' => $status, 'payment_status' => $paymentStatus,
    ]);

    // The invoice stored at booking time, as createSingleBooking() does.
    $booking->user->invoices()->create([
        'user_id' => $user->id, 'booking_id' => $booking->id, 'status' => 'generated',
        'path' => "invoices/invoice_INV_2026_00001_{$booking->id}.pdf", 'invoice_number' => 'INV_2026_00001',
    ]);

    return $booking->fresh();
}

function pendingPayment(Booking $booking, string $type, float $amount, string $ref): Payment
{
    return Payment::create([
        'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => $type,
        'amount' => $amount, 'status' => PaymentStatus::PENDING, 'method' => PaymentMethod::CASH,
        'payment_id' => $ref,
    ]);
}

function invoiceCount(Booking $booking): int
{
    return Invoice::where('booking_id', $booking->id)->count();
}

it('raises PaymentStatusChanged once for a pending row that becomes paid, and once for a row born paid', function () {
    Event::fake([PaymentStatusChanged::class]);
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);

    $pending = pendingPayment($booking, PaymentType::FULL, 200, 'PAY_1');
    $pending->update(['status' => PaymentStatus::PAID]);
    $pending->update(['notes' => 'edited afterwards']);   // not a status change

    Payment::create([
        'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => PaymentType::DEPOSIT,
        'amount' => 50, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CASH, 'payment_id' => 'PAY_2',
    ]);

    Event::assertDispatchedTimes(PaymentStatusChanged::class, 2);
});

it('does not fire for a quiet save — refunds and reconciliation stay silent by design', function () {
    Event::fake([PaymentStatusChanged::class]);
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    $payment = pendingPayment($booking, PaymentType::FULL, 200, 'PAY_1');

    $payment->forceFill(['status' => PaymentStatus::PAID])->saveQuietly();

    Event::assertNotDispatched(PaymentStatusChanged::class);
});

it('THE MERCHANT BUG: a balance collected on an already-confirmed booking regenerates the invoice and marks it fully paid', function () {
    // Deposit paid online, booking confirmed on it. Weeks later the balance
    // is collected in cash. Before: nothing regenerated the invoice, so it
    // kept saying "remaining due".
    $booking = syncBooking(User::factory()->create(), 'confirmed', BookingPaymentStatus::DEPOSIT_PAID);
    Payment::create([
        'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => PaymentType::DEPOSIT,
        'amount' => 50, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::ONLINE, 'payment_id' => 'PAY_DEP',
    ]);
    $balance = pendingPayment($booking, PaymentType::REMAINING, 150, 'PAY_BAL');
    $before  = invoiceCount($booking);

    $balance->update(['status' => PaymentStatus::PAID, 'paid_at' => now()]);

    $booking->refresh();
    expect($booking->payment_status)->toBe(BookingPaymentStatus::FULLY_PAID)
        ->and($booking->full_payment_received)->toBeTrue()
        ->and(invoiceCount($booking))->toBe($before + 1);
    Storage::disk('public')->assertExists($booking->latestInvoice->path);
    // Already confirmed, so no second "confirmed" email.
    Notification::assertNothingSent();
});

it('confirms a pending cash-on-arrival booking once its only payment lands, emailing the fresh invoice', function () {
    $user    = User::factory()->create();
    $booking = syncBooking($user, 'pending', BookingPaymentStatus::PENDING);
    $payment = pendingPayment($booking, PaymentType::FULL, 200, 'PAY_1');
    $stale   = $booking->latestInvoice;

    $payment->update(['status' => PaymentStatus::PAID]);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->payment_status)->toBe(BookingPaymentStatus::FULLY_PAID)
        ->and($booking->latestInvoice->id)->not->toBe($stale->id);
    Notification::assertSentToTimes($user, BookingConfirmedUserNotification::class, 1);
});

it('marks a deposit as DEPOSIT_PAID and regenerates the invoice — mobile mark-paid used to skip both for a deposit', function () {
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    $deposit = pendingPayment($booking, PaymentType::DEPOSIT, 50, 'PAY_DEP');
    $before  = invoiceCount($booking);

    $deposit->update(['status' => PaymentStatus::PAID]);

    $booking->refresh();
    expect($booking->payment_status)->toBe(BookingPaymentStatus::DEPOSIT_PAID)
        ->and($booking->full_payment_received)->toBeFalse()
        ->and(invoiceCount($booking))->toBe($before + 1);
});

it('regenerates ONE invoice when several payments land in one transaction', function () {
    // markBookingAsPaid() flips every pending row in a loop inside one
    // transaction; the booking must not get an invoice per row.
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    $deposit = pendingPayment($booking, PaymentType::DEPOSIT, 50, 'PAY_DEP');
    $balance = pendingPayment($booking, PaymentType::REMAINING, 150, 'PAY_BAL');
    $before  = invoiceCount($booking);

    DB::transaction(function () use ($deposit, $balance) {
        $deposit->update(['status' => PaymentStatus::PAID]);
        $balance->update(['status' => PaymentStatus::PAID]);
    });

    expect(invoiceCount($booking))->toBe($before + 1)
        ->and($booking->fresh()->payment_status)->toBe(BookingPaymentStatus::FULLY_PAID);
});

it('waits for the transaction to commit, and does nothing when it rolls back', function () {
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    $payment = pendingPayment($booking, PaymentType::FULL, 200, 'PAY_1');
    $before  = invoiceCount($booking);

    try {
        DB::transaction(function () use ($payment) {
            $payment->update(['status' => PaymentStatus::PAID]);
            throw new RuntimeException('simulated failure after the write');
        });
    } catch (RuntimeException) {
    }

    expect(invoiceCount($booking))->toBe($before)
        ->and($booking->fresh()->status)->toBe('pending');
    Notification::assertNothingSent();
});

it('ignores the internal split-ledger rows that share the booking', function () {
    // booking_commission + booking_earning are born paid against the same
    // booking and together equal the customer's money. They are not receipts
    // and must neither confirm the booking nor regenerate anything.
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    $before  = invoiceCount($booking);

    foreach ([[PaymentType::BOOKING_COMMISSION, 40], [PaymentType::BOOKING_EARNING, 160]] as [$type, $amount]) {
        Payment::create([
            'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => $type,
            'amount' => $amount, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::ONLINE,
            'payment_id' => 'LEDGER_' . $type,
        ]);
    }

    expect(invoiceCount($booking))->toBe($before)
        ->and($booking->fresh()->status)->toBe('pending')
        ->and($booking->fresh()->payment_status)->toBe(BookingPaymentStatus::PENDING);
    Notification::assertNothingSent();
});

it('never counts the split-ledger rows as money collected', function () {
    $booking = syncBooking(User::factory()->create(), 'confirmed', BookingPaymentStatus::FULLY_PAID);
    foreach ([[PaymentType::FULL, 200], [PaymentType::BOOKING_COMMISSION, 40], [PaymentType::BOOKING_EARNING, 160]] as [$type, $amount]) {
        Payment::create([
            'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => $type,
            'amount' => $amount, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::ONLINE,
            'payment_id' => 'ROW_' . $type,
        ]);
    }

    expect(BookingPaymentSync::collected($booking))->toBe(200.0);
});

it('leaves a payment-skipped or cancelled booking alone', function () {
    foreach ([
        ['confirmed', BookingPaymentStatus::PAYMENT_SKIPPED],
        ['cancelled', BookingPaymentStatus::CANCELLED],
    ] as [$status, $paymentStatus]) {
        $booking = syncBooking(User::factory()->create(), $status, $paymentStatus);
        $before  = invoiceCount($booking);
        pendingPayment($booking, PaymentType::FULL, 200, 'PAY_' . $status)->update(['status' => PaymentStatus::PAID]);

        $booking->refresh();
        expect($booking->status)->toBe($status)
            ->and($booking->payment_status)->toBe($paymentStatus)
            ->and(invoiceCount($booking))->toBe($before);
    }
});

it('a payment cancelled afterwards pulls the booking back from fully paid', function () {
    $booking = syncBooking(User::factory()->create(), 'confirmed', BookingPaymentStatus::FULLY_PAID);
    $payment = Payment::create([
        'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'type' => PaymentType::FULL,
        'amount' => 200, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CASH, 'payment_id' => 'PAY_1',
    ]);
    $before = invoiceCount($booking);

    $payment->update(['status' => PaymentStatus::CANCELLED]);

    expect($booking->fresh()->payment_status)->toBe(BookingPaymentStatus::PENDING)
        ->and($booking->fresh()->full_payment_received)->toBeFalse()
        ->and(invoiceCount($booking))->toBe($before + 1);
});

it('is idempotent: running the sync again changes nothing', function () {
    $booking = syncBooking(User::factory()->create(), 'pending', BookingPaymentStatus::PENDING);
    pendingPayment($booking, PaymentType::FULL, 200, 'PAY_1')->update(['status' => PaymentStatus::PAID]);
    $invoices = invoiceCount($booking);
    $latest   = $booking->fresh()->latestInvoice->id;

    expect(BookingPaymentSync::syncPaymentStatus($booking->fresh()))->toBeFalse();
    expect(autoConfirmBookingIfPaid($booking->fresh()))->toBeFalse();
    expect(invoiceCount($booking))->toBe($invoices)
        ->and($booking->fresh()->latestInvoice->id)->toBe($latest);
});
