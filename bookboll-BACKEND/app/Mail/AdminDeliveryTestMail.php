<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The message the admin panel's Delivery Tests page sends.
 *
 * It carries enough about its own origin — who sent it, from which
 * environment, through which mailer — that the person reading it in an inbox
 * can tell which configuration just proved itself.
 */
class AdminDeliveryTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $body,
        public string $sentBy,
        public string $mailerLabel,
    ) {}

    public function build()
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.admin-delivery-test')
            ->with([
                'bodyText' => $this->body,
                'sentBy' => $this->sentBy,
                'mailerLabel' => $this->mailerLabel,
                'environment' => app()->environment(),
                'sentAt' => now(),
            ]);
    }
}
