<?php

/**
 * The booking confirmation email carries the booking's invoice — attached as a
 * PDF, with a link as the fallback.
 *
 * The invoice PDF has always been generated and stored against the booking, but
 * nothing ever showed it to the customer — they had to call the generate-invoice
 * endpoint to get at it. The confirmation email now links the latest one.
 *
 * The link is a markdown line, not a ->action(): MailMessage holds a single
 * action, so adding a third would silently replace 'View Booking'.
 */

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Models\Payment;
use App\Notifications\BookingConfirmedUserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** A confirmed booking, with the payment status under test. */
function invoiceLinkBooking(User $user, ?string $paymentStatus): Booking
{
    $venue = Venue::create([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'currency_override' => 'QAR',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $listing = Listing::create([
        'name' => 'Indoor Padel Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 175, 'capacity' => 4, 'slot_duration' => 2,
        'payment_options' => ['cash_on_arrival' => '0'], 'max_bookings_per_slot' => 1,
    ]);

    $booking = Booking::create([
        'user_id' => $user->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'booking_id' => 'BKN_2026_11_02154', 'date' => now()->addDays(3)->toDateString(),
        'start_time' => '19:00', 'end_time' => '21:00',
        'subtotal' => 175, 'total_price' => 175, 'status' => 'confirmed',
        'payment_status' => $paymentStatus,
    ]);

    return $booking->fresh()->load('user', 'venue', 'listing');
}

/** Every line of the rendered confirmation mail. */
function confirmationBody(Booking $booking, User $user): string
{
    $mail = (new BookingConfirmedUserNotification($booking))->toMail($user);

    return implode("\n", array_map(
        fn ($line) => (string) $line,
        array_merge($mail->introLines, $mail->outroLines)
    ));
}

function invoiceFor(Booking $booking, string $path): Invoice
{
    return $booking->user->invoices()->create([
        'user_id'        => $booking->user_id,
        'booking_id'     => $booking->id,
        'status'         => 'generated',
        'path'           => $path,
        'invoice_number' => preg_match('/(INV_\d{4}_\d{5})/', $path, $m) ? $m[1] : null,
    ]);
}

it('links the invoice on a fully paid booking', function () {
    $user    = User::factory()->create(['name' => 'Syazwan']);
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    $invoice = invoiceFor($booking, 'invoices/invoice_INV_2026_00008_1.pdf');

    expect(confirmationBody($booking->fresh(), $user))
        ->toContain($invoice->full_path)
        ->toContain('Download your invoice');
});

it('links the LATEST invoice when the booking has been re-invoiced', function () {
    // regenerateBookingInvoice() marks the old row re_generated and writes a new
    // one, so the customer must be sent the newest, not the first.
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);

    $first = invoiceFor($booking, 'invoices/invoice_INV_2026_00008_1.pdf');
    $first->update(['status' => 're_generated']);
    $latest = invoiceFor($booking, 'invoices/invoice_INV_2026_00009_1.pdf');

    $body = confirmationBody($booking->fresh(), $user);

    expect($body)->toContain($latest->full_path)
        ->and($body)->not->toContain($first->full_path);
});

it('links the invoice on a deposit-paid booking too', function () {
    // Real money changed hands, so there is a real invoice worth reading.
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::DEPOSIT_PAID);
    $invoice = invoiceFor($booking, 'invoices/invoice_INV_2026_00010_1.pdf');

    expect(confirmationBody($booking->fresh(), $user))->toContain($invoice->full_path);
});

it('says nothing about an invoice when none has been generated', function () {
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);

    expect(confirmationBody($booking, $user))->not->toContain('Download your invoice');
});

it('withholds the link while the booking is still unpaid or cancelled', function () {
    foreach ([BookingPaymentStatus::PENDING, BookingPaymentStatus::CANCELLED] as $status) {
        $user    = User::factory()->create();
        $booking = invoiceLinkBooking($user, $status);
        invoiceFor($booking, 'invoices/invoice_INV_2026_00011_'.$booking->id.'.pdf');

        expect(confirmationBody($booking->fresh(), $user))
            ->not->toContain('Download your invoice');
    }
});

it('keeps the single mail action pointing at the booking', function () {
    // The invoice had to be a line, not an action: MailMessage stores one
    // action, so a third ->action() call would have replaced this one.
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    invoiceFor($booking, 'invoices/invoice_INV_2026_00012_1.pdf');

    $mail = (new BookingConfirmedUserNotification($booking->fresh()))->toMail($user);

    expect($mail->actionText)->toBe('View Booking')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url'));
});

it('attaches the invoice PDF when the file exists', function () {
    Storage::fake('public');
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    $invoice = invoiceFor($booking, 'invoices/invoice_INV_2026_00020_1.pdf');
    Storage::disk('public')->put($invoice->path, '%PDF-1.4 test');

    $mail = (new BookingConfirmedUserNotification($booking->fresh()))->toMail($user);

    expect($mail->attachments)->toHaveCount(1)
        ->and($mail->attachments[0]['file'])->toBe(Storage::disk('public')->path($invoice->path))
        ->and($mail->attachments[0]['options']['as'])->toBe('Invoice-'.$booking->fresh()->booking_id.'.pdf')
        ->and($mail->attachments[0]['options']['mime'])->toBe('application/pdf');
});

it('still links, but does not attach, when the PDF is missing from disk', function () {
    // A missing file must never turn a confirmation email into an exception.
    Storage::fake('public');
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    $invoice = invoiceFor($booking, 'invoices/invoice_INV_2026_00021_1.pdf');

    $mail = (new BookingConfirmedUserNotification($booking->fresh()))->toMail($user);

    expect($mail->attachments)->toBeEmpty()
        ->and(confirmationBody($booking->fresh(), $user))->toContain($invoice->full_path);
});

it('regenerates the invoice BEFORE sending the confirmation, so the emailed PDF shows the payment', function () {
    // The invoice stored at booking time shows the payment as pending. Every
    // caller used to regenerate only after autoConfirmBookingIfPaid() returned —
    // after the stale PDF had already gone out.
    Storage::fake('public');
    Notification::fake();

    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    $booking->update(['status' => 'pending']);
    $stale = invoiceFor($booking, 'invoices/invoice_INV_2026_00001_'.$booking->id.'.pdf');

    Payment::create([
        'booking_id' => $booking->id, 'user_id' => $user->id, 'type' => PaymentType::FULL,
        'amount' => 175, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CASH,
        'date' => now()->toDateString(), 'payment_id' => 'PAY_TEST_0001',
    ]);

    $confirmed = autoConfirmBookingIfPaid($booking->fresh());

    expect($confirmed)->toBeTrue();
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(2);
    expect($stale->fresh()->status)->toBe('re_generated');

    $latest = $booking->fresh()->latestInvoice;
    expect($latest->id)->not->toBe($stale->id);
    Storage::disk('public')->assertExists($latest->path);

    Notification::assertSentTo($user, BookingConfirmedUserNotification::class, function ($n) use ($latest) {
        return $n->booking->latestInvoice->id === $latest->id;
    });
});

it('reports false when it does not confirm, so callers regenerate themselves', function () {
    Notification::fake();
    $user    = User::factory()->create();
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID); // already confirmed

    expect(autoConfirmBookingIfPaid($booking))->toBeFalse();
    Notification::assertNothingSent();
});

it('delivers the PDF as a real MIME attachment, with the payment facts in the body', function () {
    // Through the real mail pipeline (array transport), not just the
    // MailMessage object — what the customer's mail client actually receives.
    Storage::fake('public');
    $user    = User::factory()->create(['name' => 'Syazwan']);
    $booking = invoiceLinkBooking($user, BookingPaymentStatus::FULLY_PAID);
    $invoice = invoiceFor($booking, 'invoices/invoice_INV_2026_00030_1.pdf');
    Storage::disk('public')->put($invoice->path, '%PDF-1.4 test');
    Payment::create([
        'booking_id' => $booking->id, 'user_id' => $user->id, 'type' => PaymentType::FULL,
        'amount' => 175, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CREDIT_CARD,
        'date' => now()->toDateString(), 'paid_at' => now(), 'payment_id' => 'PAY_TEST_0030',
    ]);
    // A split-ledger row must never be counted as the customer's money.
    Payment::create([
        'booking_id' => $booking->id, 'user_id' => $user->id, 'type' => PaymentType::BOOKING_EARNING,
        'amount' => 150, 'status' => PaymentStatus::PAID, 'method' => PaymentMethod::CREDIT_CARD,
        'date' => now()->toDateString(), 'payment_id' => 'PAY_TEST_0031',
    ]);

    $user->notify(new BookingConfirmedUserNotification($booking->fresh()));

    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();

    $attachments = $email->getAttachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getFilename())->toBe('Invoice-'.$booking->fresh()->booking_id.'.pdf')
        ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
        ->and($attachments[0]->getBody())->toBe('%PDF-1.4 test');

    $html = $email->getHtmlBody();
    expect($html)->toContain('Amount paid: QAR 175.00')
        ->not->toContain('325.00')
        ->toContain('Method: Credit Card')
        ->toContain('Invoice number: INV_2026_00030')
        ->toContain('Download your invoice');
});
