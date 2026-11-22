<?php

namespace App\Domain\StatusPages\Mail;

use App\Domain\StatusPages\Models\StatusPage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Double opt-in: sent synchronously from SendSubscriptionConfirmation (a
 * queued job), in the language of the page's organization.
 */
class SubscriptionConfirmationMail extends Mailable
{
    public function __construct(
        public readonly StatusPage $statusPage,
        public readonly string $confirmUrl,
        public readonly string $pageUrl,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm your subscription to :page', ['page' => $this->statusPage->title]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.status-page-subscription', with: [
            'pageTitle' => $this->statusPage->title,
            'validHours' => (int) config('veyra.status_pages.confirmation_valid_hours', 48),
        ]);
    }
}
