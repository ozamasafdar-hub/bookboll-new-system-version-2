<?php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChatUnreadNotification extends Notification
{
    public function __construct(public string $title, public string $url, public bool $mentioned) {}
    public function via($notifiable): array { return ['mail']; }
    public function toMail($notifiable): MailMessage
    {
        // No private message text is copied into email or WhatsApp.
        return (new MailMessage)->subject($this->mentioned ? 'You were mentioned on Bookboll' : 'New messages on Bookboll')
            ->line($this->mentioned ? 'You have an unread @mention in '.$this->title.'.' : 'You have unread messages in '.$this->title.'.')
            ->action('Open chat', $this->url)
            ->line('Manage chat email and WhatsApp alerts in your Bookboll account settings.');
    }
}
