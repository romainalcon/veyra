<?php

namespace App\Http\Presenters;

use App\Domain\Monitoring\Certificates\CheckCertificateExpiry;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Http\Requests\Monitors\SaveMonitorRequest;
use Carbon\CarbonImmutable;

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
            'type' => $monitor->type->value,
            'target' => $monitor->target(),
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
            'host' => $monitor->tcpConfig?->host,
            'port' => $monitor->tcpConfig?->port,
            'heartbeat' => $monitor->heartbeatConfig === null ? null : [
                'grace_seconds' => $monitor->heartbeatConfig->grace_seconds,
                'last_ping_at' => $monitor->heartbeatConfig->last_ping_at?->toIso8601String(),
                'last_ping_failed' => $monitor->heartbeatConfig->last_ping_failed,
                // The token is never stored in plain text: only the URL shape is shown.
                'ping_url_prefix' => url('ping').'/',
            ],
            'certificate' => $monitor->type === MonitorType::Http && $monitor->tlsCertificate !== null
                ? self::certificate($monitor->tlsCertificate)
                : null,
            'notification_channel_ids' => $monitor->notificationChannels()->pluck('notification_channels.id')->all(),
            'next_check_at' => $monitor->next_check_at?->toIso8601String(),
            'created_at' => $monitor->created_at?->toIso8601String(),
        ];
    }

    /**
     * Public facts of the last certificate seen. `status` is `expiring` from
     * the first warning threshold (ADR-026) and `expired` once `not_after` passed.
     *
     * @return array{subject: string|null, issuer: string|null, not_before: string, not_after: string, checked_at: string, days_left: int, status: 'valid'|'expiring'|'expired'}
     */
    public static function certificate(MonitorTlsCertificate $certificate, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $threshold = CheckCertificateExpiry::thresholdFor($certificate->not_after, $now, CheckCertificateExpiry::thresholds());

        return [
            'subject' => $certificate->subject,
            'issuer' => $certificate->issuer,
            'not_before' => $certificate->not_before->toIso8601String(),
            'not_after' => $certificate->not_after->toIso8601String(),
            'checked_at' => $certificate->checked_at->toIso8601String(),
            'days_left' => (int) floor($now->diffInDays($certificate->not_after, false)),
            'status' => match (true) {
                $threshold === 0 => 'expired',
                $threshold !== null => 'expiring',
                default => 'valid',
            },
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
            'error_label' => $check->error_code === null ? null : (string) __($check->error_code->label()),
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
            'types' => array_map(fn (MonitorType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], SaveMonitorRequest::CREATABLE_TYPES),
            'tcpPorts' => app(ProbeSettings::class)->tcpAllowedPorts,
            'heartbeatPeriods' => HeartbeatPeriod::options(),
            'maxGraceMinutes' => SaveMonitorRequest::MAX_GRACE_MINUTES,
        ];
    }
}
