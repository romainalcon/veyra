<?php

namespace App\Domain\StatusPages\Mail;

use App\Domain\StatusPages\Enums\AnnouncementEvent;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAnnouncement;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A status page announcement for one subscriber, built from the frozen,
 * whitelisted payload only. Carries one-click unsubscribe headers (RFC 8058).
 */
class StatusPageAnnouncementMail extends Mailable
{
    public function __construct(
        public readonly StatusPage $statusPage,
        public readonly StatusPageAnnouncement $announcement,
        public readonly string $unsubscribeUrl,
        public readonly string $pageUrl,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function headline(): string
    {
        $payload = $this->announcement->payload;

        return match ($this->announcement->event) {
            AnnouncementEvent::IncidentOpened => __('Outage — :component', ['component' => $payload['component'] ?? '']),
            AnnouncementEvent::IncidentUpdated => __('Update — :component', ['component' => $payload['component'] ?? '']),
            AnnouncementEvent::IncidentResolved => __('Resolved — :component', ['component' => $payload['component'] ?? '']),
            AnnouncementEvent::MaintenanceScheduled => __('Planned maintenance — :title', ['title' => $payload['title'] ?? '']),
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->statusPage->title}] ".$this->headline());
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $payload = $this->announcement->payload;
        $format = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i').' UTC';

        return new Content(markdown: 'mail.status-page-announcement', with: [
            'headline' => $this->headline(),
            'event' => $this->announcement->event,
            'pageTitle' => $this->statusPage->title,
            'message' => $payload['message'] ?? null,
            'description' => $payload['description'] ?? null,
            'components' => implode(', ', $payload['components'] ?? []),
            'at' => $format($payload['at'] ?? null),
            'startsAt' => $format($payload['starts_at'] ?? null),
            'endsAt' => $format($payload['ends_at'] ?? null),
        ]);
    }
}
