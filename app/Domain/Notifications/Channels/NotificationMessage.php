<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Enums\NotificationEvent;

/**
 * Channel-agnostic content of one notification. Contains no secrets. Text is
 * rendered in the organization language carried by `$locale` (ADR-027),
 * whatever the locale of the process that sends it.
 *
 * The identifiers, `rawCause` (English source label) and `occurredAt` (UTC,
 * stable across retries) feed the machine-readable webhook payload (ADR-033).
 */
final readonly class NotificationMessage
{
    public function __construct(
        public NotificationEvent $event,
        public string $organizationName,
        public string $monitorName,
        public ?string $cause,
        public ?string $startedAt,
        public ?string $resolvedAt,
        public ?int $durationSeconds,
        public ?string $acknowledgedBy,
        public string $url,
        public ?string $certificateExpiresAt = null,
        public ?string $certificateIssuer = null,
        public string $locale = 'en',
        public ?string $deliveryId = null,
        public ?string $organizationId = null,
        public ?string $organizationSlug = null,
        public ?string $monitorId = null,
        public ?string $monitorType = null,
        public ?string $incidentId = null,
        public ?string $rawCause = null,
        public ?string $acknowledgedAt = null,
        public ?string $occurredAt = null,
    ) {}

    public function title(): string
    {
        return "[Veyra] {$this->event->headline($this->locale)} — {$this->monitorName}";
    }

    public function summary(): string
    {
        $replace = [
            'monitor' => $this->monitorName,
            'organization' => $this->organizationName,
            'person' => (string) $this->acknowledgedBy,
            'duration' => self::humanDuration($this->durationSeconds, $this->locale),
        ];

        return $this->translate(match ($this->event) {
            NotificationEvent::Opened => ':monitor is down.',
            NotificationEvent::Resolved => ':monitor is back up after :duration.',
            NotificationEvent::Acknowledged => ':person acknowledged the incident on :monitor.',
            NotificationEvent::Reminder => ':monitor has been down for :duration and the incident is not acknowledged.',
            NotificationEvent::Test => 'This is a test notification from :organization.',
            NotificationEvent::CertificateExpiring => 'The TLS certificate of :monitor expires in :duration. Renew it before visitors get errors.',
            NotificationEvent::CertificateExpired => 'The TLS certificate of :monitor has expired. HTTPS checks fail until it is renewed.',
        }, $replace);
    }

    /**
     * Translates a label of the notification into its language.
     *
     * @param  array<string, string>  $replace
     */
    public function translate(string $key, array $replace = []): string
    {
        return (string) __($key, $replace, $this->locale);
    }

    public static function humanDuration(?int $seconds, string $locale = 'en'): string
    {
        $seconds = max(0, (int) $seconds);

        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m',
            default => (string) __(':daysd :hoursh', ['days' => intdiv($seconds, 86400), 'hours' => intdiv($seconds % 86400, 3600)], $locale),
        };
    }
}
