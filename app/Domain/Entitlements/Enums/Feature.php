<?php

namespace App\Domain\Entitlements\Enums;

/**
 * Organization capabilities that an edition may limit (ADR-029).
 *
 * Counted features cap a number of resources; the others carry a value
 * (a minimum interval, a retention period).
 */
enum Feature: string
{
    case Monitors = 'monitors';
    case Members = 'members';
    case StatusPages = 'status_pages';
    case CustomDomains = 'custom_domains';
    case NotificationChannels = 'notification_channels';
    case MinIntervalSeconds = 'min_interval_seconds';
    case CheckRetentionDays = 'check_retention_days';

    public function isCounted(): bool
    {
        return match ($this) {
            self::MinIntervalSeconds, self::CheckRetentionDays => false,
            default => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function counted(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature): bool => $feature->isCounted()));
    }

    /**
     * English message template shown when the limit is reached (`:limit`).
     */
    public function limitMessage(): string
    {
        return match ($this) {
            self::Monitors => 'This organization is limited to :limit active monitors.',
            self::Members => 'This organization is limited to :limit members, including pending invitations.',
            self::StatusPages => 'This organization is limited to :limit status pages.',
            self::CustomDomains => 'This organization is limited to :limit custom domains.',
            self::NotificationChannels => 'This organization is limited to :limit notification channels.',
            self::MinIntervalSeconds, self::CheckRetentionDays => 'This organization has reached a limit.',
        };
    }
}
