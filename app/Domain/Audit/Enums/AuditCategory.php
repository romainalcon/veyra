<?php

namespace App\Domain\Audit\Enums;

/**
 * Resource families of the audit log, the prefix of each action value.
 */
enum AuditCategory: string
{
    case Organization = 'organization';
    case Member = 'member';
    case ApiToken = 'api_token';
    case Monitor = 'monitor';
    case Monitors = 'monitors';
    case NotificationChannel = 'notification_channel';
    case EscalationPolicy = 'escalation_policy';
    case StatusPage = 'status_page';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Organization => 'Settings',
            self::Member => 'Members',
            self::ApiToken => 'API tokens',
            self::Monitor, self::Monitors => 'Monitors',
            self::NotificationChannel => 'Notification channels',
            self::EscalationPolicy => 'Escalation policies',
            self::StatusPage => 'Status pages',
        });
    }

    /**
     * Action prefixes covered by a filter: monitor filters include bulk
     * import and export.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Monitor, self::Monitors => [self::Monitor->value.'.', self::Monitors->value.'.'],
            default => [$this->value.'.'],
        };
    }

    /**
     * Categories offered as filters (bulk monitor actions are merged into monitors).
     *
     * @return list<self>
     */
    public static function filters(): array
    {
        return array_values(array_filter(self::cases(), fn (self $category): bool => $category !== self::Monitors));
    }
}
