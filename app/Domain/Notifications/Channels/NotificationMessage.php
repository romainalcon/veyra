<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Enums\NotificationEvent;

/**
 * Channel-agnostic content of one notification. Contains no secrets.
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
    ) {}

    public function title(): string
    {
        return "[Veyra] {$this->event->headline()} — {$this->monitorName}";
    }

    public function summary(): string
    {
        return match ($this->event) {
            NotificationEvent::Opened => "{$this->monitorName} is down.",
            NotificationEvent::Resolved => "{$this->monitorName} is back up after ".self::humanDuration($this->durationSeconds).'.',
            NotificationEvent::Acknowledged => "{$this->acknowledgedBy} acknowledged the incident on {$this->monitorName}.",
            NotificationEvent::Reminder => "{$this->monitorName} has been down for ".self::humanDuration($this->durationSeconds).' and the incident is not acknowledged.',
            NotificationEvent::Test => "This is a test notification from {$this->organizationName}.",
            NotificationEvent::CertificateExpiring => "The TLS certificate of {$this->monitorName} expires in ".self::humanDuration($this->durationSeconds).'. Renew it before visitors get errors.',
            NotificationEvent::CertificateExpired => "The TLS certificate of {$this->monitorName} has expired. HTTPS checks fail until it is renewed.",
        };
    }

    public static function humanDuration(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m',
            default => intdiv($seconds, 86400).'d '.intdiv($seconds % 86400, 3600).'h',
        };
    }
}
