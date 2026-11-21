<?php

namespace App\Http\Presenters;

use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;

/**
 * Explicit, whitelisted Inertia representations. Header values never leave
 * the server; only header names are exposed.
 */
final class MonitorPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Monitor $monitor): array
    {
        $config = $monitor->httpConfig;

        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'url' => $config?->url,
            'method' => $config?->method->value,
            'enabled' => $monitor->enabled,
            'interval_seconds' => $monitor->interval_seconds,
            'latest_check' => $monitor->latestCheck ? self::check($monitor->latestCheck) : null,
            'state' => [
                'status' => ($monitor->state->status ?? MonitorStatus::Unknown)->value,
                'last_changed_at' => $monitor->state?->last_changed_at?->toIso8601String(),
                'active_incident_id' => $monitor->state?->active_incident_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Monitor $monitor): array
    {
        $config = $monitor->httpConfig;

        return [
            ...self::summary($monitor),
            'timeout_seconds' => $monitor->timeout_seconds,
            'expected_statuses' => $config?->expected_statuses->toExpression(),
            'follow_redirects' => $config->follow_redirects ?? true,
            'header_names' => $config?->headerNames() ?? [],
            'notification_channel_ids' => $monitor->notificationChannels()->pluck('notification_channels.id')->all(),
            'next_check_at' => $monitor->next_check_at?->toIso8601String(),
            'created_at' => $monitor->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function check(MonitorCheck $check): array
    {
        return [
            'id' => $check->id,
            'checked_at' => $check->checked_at->toIso8601String(),
            'success' => $check->success,
            'duration_ms' => $check->duration_ms,
            'status_code' => $check->status_code,
            'error_code' => $check->error_code?->value,
            'error_label' => $check->error_code?->label(),
            'redirects' => (int) ($check->metadata['redirects'] ?? 0),
            'in_maintenance' => $check->in_maintenance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formOptions(Organization $organization): array
    {
        return [
            'channels' => $organization->notificationChannels()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_default', 'enabled'])
                ->map(fn (NotificationChannel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type->value,
                    'is_default' => $channel->is_default,
                    'enabled' => $channel->enabled,
                ])
                ->all(),
            'intervals' => MonitorInterval::options(),
            'methods' => array_map(fn (HttpMethod $method): string => $method->value, HttpMethod::cases()),
        ];
    }
}
