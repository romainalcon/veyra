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
        public ?string $domainName = null,
        public ?string $domainExpiresAt = null,
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
            'domain' => (string) $this->domainName,
        ];

        return $this->translate(match ($this->event) {
            NotificationEvent::Opened => ':monitor is down.',
            NotificationEvent::Resolved => ':monitor is back up after :duration.',
            NotificationEvent::Acknowledged => ':person acknowledged the incident on :monitor.',
            NotificationEvent::Reminder => ':monitor has been down for :duration and the incident is not acknowledged.',
            NotificationEvent::Test => 'This is a test notification from :organization.',
            NotificationEvent::CertificateExpiring => 'The TLS certificate of :monitor expires in :duration. Renew it before visitors get errors.',
            NotificationEvent::CertificateExpired => 'The TLS certificate of :monitor has expired. HTTPS checks fail until it is renewed.',
            NotificationEvent::DomainExpiring => 'The domain :domain of :monitor expires in :duration. Renew it with your registrar.',
            NotificationEvent::DomainExpired => 'The domain :domain of :monitor has expired. Renew it with your registrar before it is released.',
        }, $replace);
    }

    /**
     * Labelled details shown by plain-text and card channels, in the
     * notification language. Values are untrusted: each channel escapes them.
     *
     * @return list<array{label: string, value: string}>
     */
    public function facts(): array
    {
        $isIncident = $this->event->isIncidentEvent();

        return array_values(array_filter([
            ['label' => $this->translate('Monitor'), 'value' => $this->monitorName],
            $this->cause ? ['label' => $this->translate('Cause'), 'value' => mb_substr($this->cause, 0, 256)] : null,
            $this->durationSeconds !== null && $isIncident
                ? ['label' => $this->translate('Duration'), 'value' => self::humanDuration($this->durationSeconds, $this->locale)]
                : null,
            $this->certificateExpiresAt ? ['label' => $this->translate('Expires'), 'value' => $this->certificateExpiresAt.' UTC'] : null,
            $this->certificateIssuer ? ['label' => $this->translate('Issuer'), 'value' => mb_substr($this->certificateIssuer, 0, 256)] : null,
            $this->domainName ? ['label' => $this->translate('Domain'), 'value' => $this->domainName] : null,
            $this->domainExpiresAt ? ['label' => $this->translate('Expires'), 'value' => $this->domainExpiresAt.' UTC'] : null,
        ]));
    }

    /**
     * Summary followed by one "Label: value" line per fact (no markup).
     */
    public function plainText(): string
    {
        $lines = array_map(fn (array $fact): string => $this->translate(':label: :value', $fact), $this->facts());

        return $this->summary()."\n\n".implode("\n", $lines);
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
