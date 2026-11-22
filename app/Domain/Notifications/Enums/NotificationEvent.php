<?php

namespace App\Domain\Notifications\Enums;

enum NotificationEvent: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
    case Reminder = 'reminder';
    /** Manual "send test" from the channel settings (no incident). */
    case Test = 'test';
    /** TLS certificate of an HTTPS monitor close to expiry (no incident). */
    case CertificateExpiring = 'certificate_expiring';
    /** TLS certificate of an HTTPS monitor expired (no incident). */
    case CertificateExpired = 'certificate_expired';
    /** Registration of a monitored domain close to expiry (no incident, ADR-043). */
    case DomainExpiring = 'domain_expiring';
    /** Registration of a monitored domain expired (no incident, ADR-043). */
    case DomainExpired = 'domain_expired';

    /**
     * Expiry warnings and tests are not about an incident: no duration,
     * acknowledgement or resolution.
     */
    public function isIncidentEvent(): bool
    {
        return in_array($this, [self::Opened, self::Resolved, self::Acknowledged, self::Reminder], true);
    }

    public function headline(?string $locale = null): string
    {
        return (string) __(match ($this) {
            self::Opened => 'DOWN',
            self::Resolved => 'RESOLVED',
            self::Acknowledged => 'ACKNOWLEDGED',
            self::Reminder => 'STILL DOWN',
            self::Test => 'TEST',
            self::CertificateExpiring => 'CERTIFICATE EXPIRES SOON',
            self::CertificateExpired => 'CERTIFICATE EXPIRED',
            self::DomainExpiring => 'DOMAIN EXPIRES SOON',
            self::DomainExpired => 'DOMAIN EXPIRED',
        }, [], $locale);
    }

    /**
     * Stable, language-independent event name of the webhook payload (ADR-033).
     */
    public function webhookName(): string
    {
        return match ($this) {
            self::Opened => 'incident.opened',
            self::Resolved => 'incident.resolved',
            self::Acknowledged => 'incident.acknowledged',
            self::Reminder => 'incident.reminder',
            self::Test => 'test',
            self::CertificateExpiring => 'certificate.expiring',
            self::CertificateExpired => 'certificate.expired',
            self::DomainExpiring => 'domain.expiring',
            self::DomainExpired => 'domain.expired',
        };
    }

    /**
     * Discord embed and Slack attachment colour.
     */
    public function color(): int
    {
        return match ($this) {
            self::Opened, self::Reminder, self::CertificateExpired, self::DomainExpired => 0xDC2626,
            self::Resolved => 0x16A34A,
            self::Acknowledged, self::CertificateExpiring, self::DomainExpiring => 0xD97706,
            self::Test => 0x6B7280,
        };
    }
}
