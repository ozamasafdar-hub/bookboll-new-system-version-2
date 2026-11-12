<?php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Delivered by the idempotent comeback job, not a second independently queued notification. */
class BookingReminderNotification extends Notification
{
    public function __construct(protected int $daysSinceLastBooking, protected $promocode = null, protected $venue = null) {}
    public function via($notifiable) { return ['mail','database']; }
    public static function url($venue): string { return rtrim(config('app.frontend_url'),'/').'/facilities/'.$venue->id; }
    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Book again at '.$this->venue->name)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Thanks for your first visit to '.$this->venue->name.'. Ready for your next session?')
            ->line('Use **'.$this->promocode->code.'** for up to **'.$this->promocode->discount_value.'% off** another booking at this venue on Bookboll marketplace.')
            ->line('The discount is capped at Bookboll’s platform fee. One use, for your account only.')
            ->line('Valid until '.$this->promocode->ends_at->format('d M Y H:i T').'.')
            ->action('Book this venue again',self::url($this->venue));
    }
    public function toArray($notifiable) { return ['type'=>'booking_reminder','message'=>'Your same-venue comeback offer is ready.','url'=>self::url($this->venue),'code'=>$this->promocode->code,'expires_at'=>$this->promocode->ends_at->toIso8601String()]; }
}
