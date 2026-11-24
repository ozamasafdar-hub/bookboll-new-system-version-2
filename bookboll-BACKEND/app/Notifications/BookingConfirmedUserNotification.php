<?php
namespace App\Notifications;

use App\Models\Booking;
use App\Support\BookingDate;
use BookingPaymentStatus;
use BookingStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
            ->line('Total: QAR ' . number_format($this->booking->total_price, 2))
            ->line('Status: Confirmed');

        if ($this->booking->venue->maps_url) {
            $mail->action('View Location on Google Maps', $this->booking->venue->maps_url);
        }

        $mail->action('View Booking', config('app.frontend_url'));

        // Rendered as a markdown line rather than another ->action():
        // MailMessage holds a single action, so a third call would silently
        // replace 'View Booking' above.
        if ($invoiceUrl = $this->invoiceUrl()) {
            $mail->line('Invoice: [Download your invoice](' . $invoiceUrl . ')');
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
    protected function invoiceUrl(): ?string
    {
        if (in_array($this->booking->payment_status, [
            BookingPaymentStatus::PENDING,
            BookingPaymentStatus::CANCELLED,
        ], true)) {
            return null;
        }

        return $this->booking->latestInvoice?->full_path;
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
