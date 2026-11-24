<?php
namespace App\Notifications;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\BookingDate;
use BookingPaymentStatus;
use BookingStatus;
use PaymentStatus;
use PaymentType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class BookingConfirmedUserNotification extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking)
    {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $mail = (new MailMessage)
            ->subject('Your Booking Has Been Confirmed! - #' . $this->booking->booking_id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your booking #' . $this->booking->booking_id . ' has been confirmed.')
            ->line('**Details:**')
            ->line('Venue: ' . $this->booking->venue->name)
            ->line('Activity: ' . $this->booking->listing->name)
            ->line('Date: ' . BookingDate::labelOrUnknown($this->booking->date))
            ->line('Time: ' . BookingDate::rangeOrUnknown($this->booking->start_time, $this->booking->end_time))
            ->line('Total: ' . bookingMoney($this->booking->total_price, $this->booking))
            ->line('Status: Confirmed');

        // What was paid, the way a bank or booking platform states it on a
        // receipt: amount, method, date and the invoice number the attached
        // PDF carries. Only once money has actually landed.
        if ($invoice = $this->invoice()) {
            $paid = $this->paidPayments();
            if ($paid->isNotEmpty()) {
                $latest = $paid->sortByDesc(fn (Payment $p) => $p->paid_at ?? $p->date)->first();
                $mail->line('**Payment:**')
                    ->line('Amount paid: ' . bookingMoney($paid->sum('amount'), $this->booking))
                    ->line('Method: ' . ucwords(str_replace('_', ' ', (string) $latest->method)))
                    ->line('Paid on: ' . BookingDate::labelOrUnknown($latest->paid_at ?? $latest->date));
            }
            if ($invoice->invoice_number) {
                $mail->line('Invoice number: ' . $invoice->invoice_number);
            }
        }

        if ($this->booking->venue->maps_url) {
            $mail->action('View Location on Google Maps', $this->booking->venue->maps_url);
        }

        $mail->action('View Booking', config('app.frontend_url'));

        // The invoice PDF rides along as an attachment, with a link as the
        // fallback for clients that strip attachments. The link is a markdown
        // line rather than another ->action(): MailMessage holds a single
        // action, so a third call would silently replace 'View Booking'.
        if ($invoice = $this->invoice()) {
            $mail->line('Invoice: [Download your invoice](' . $invoice->full_path . ')');

            if ($invoice->path && Storage::disk('public')->exists($invoice->path)) {
                $mail->attach(Storage::disk('public')->path($invoice->path), [
                    'as'   => 'Invoice-' . $this->booking->booking_id . '.pdf',
                    'mime' => 'application/pdf',
                ]);
            }
        }

        $mail->line('If you have any questions, contact us.')
            ->salutation('Best regards, BookBoll Team');

        return $mail;
    }

    /**
     * The booking's latest invoice, or null when there is nothing worth
     * linking: no invoice generated yet, or no money behind the booking.
     *
     * Deliberately not gated on FULLY_PAID alone — a deposit-paid booking has
     * really paid something, and a skipped/free one still has an invoice the
     * customer may want.
     */
    /** The customer's own payments that have landed — never the internal split-ledger rows. */
    protected function paidPayments()
    {
        return $this->booking->payments
            ->whereIn('type', [PaymentType::FULL, PaymentType::DEPOSIT, PaymentType::REMAINING])
            ->where('status', PaymentStatus::PAID);
    }

    protected function invoice(): ?Invoice
    {
        if (in_array($this->booking->payment_status, [
            BookingPaymentStatus::PENDING,
            BookingPaymentStatus::CANCELLED,
        ], true)) {
            return null;
        }

        return $this->booking->latestInvoice;
    }

    public function toArray($notifiable)
    {
        return [
            'booking_id'     => $this->booking->id,
            'booking_number' => $this->booking->booking_id,
            'status'         => BookingStatus::CONFIRMED,
            // The day they play. Without it a reader has only the notification's
            // own created_at to show, which is the day they booked.
            'date'           => BookingDate::iso($this->booking->date),
            'date_label'     => BookingDate::label($this->booking->date),
            'start_time'     => $this->booking->start_time,
            'end_time'       => $this->booking->end_time,
        ];
    }
}
