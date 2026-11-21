<?php

namespace App\Domain\Notifications\Mail;

use App\Domain\Notifications\Channels\NotificationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously from SendNotificationDelivery (already a queued job),
 * rendered in the language of the notification.
 */
class IncidentNotificationMail extends Mailable
{
    public function __construct(public readonly NotificationMessage $notification)
    {
        $this->locale($notification->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.incident-notification');
    }
}
