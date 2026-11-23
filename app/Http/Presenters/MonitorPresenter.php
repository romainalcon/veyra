<?php

namespace App\Http\Presenters;

use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Certificates\CheckCertificateExpiry;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\RequestContentType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Monitoring\Models\Tag;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Http\Requests\Monitors\SaveMonitorRequest;
use Carbon\CarbonImmutable;

/**
 * Explicit, whitelisted Inertia representations. Header values, request
 * bodies and basic auth passwords never leave the server; only header names,
 * the body size and content type and the basic auth user name are exposed.
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
            'tags' => $monitor->relationLoaded('tags') ? $monitor->tags->pluck('name')->values()->all() : [],
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
            'body' => $config !== null && $config->hasBody() ? [
                'content_type' => $config->body_content_type?->value,
                'bytes' => $config->bodyBytes(),
            ] : null,
            'basic_auth_username' => $config?->basicAuthUsername(),
            'keyword' => $config?->keyword,
            'keyword_mode' => $config?->keyword_mode?->value,
            'keyword_case_sensitive' => $config->keyword_case_sensitive ?? false,
            'latency_threshold_ms' => $config?->latency_threshold_ms,
            'host' => $monitor->tcpConfig?->host,
            'port' => $monitor->tcpConfig?->port,
            'heartbeat' => $monitor->heartbeatConfig === null ? null : [
                'grace_seconds' => $monitor->heartbeatConfig->grace_seconds,
                'last_ping_at' => $monitor->heartbeatConfig->last_ping_at?->toIso8601String(),
                'last_ping_failed' => $monitor->heartbeatConfig->last_ping_failed,
                // The token is never stored in plain text: only the URL shape is shown.
                'ping_url_prefix' => url('ping').'/',
            ],
            'dns' => $monitor->dnsConfig === null ? null : [
                'record_name' => $monitor->dnsConfig->record_name,
                'record_type' => $monitor->dnsConfig->record_type->value,
                'resolver' => $monitor->dnsConfig->resolver->value,
                'resolver_label' => $monitor->dnsConfig->resolver->label(),
                'expected_values' => $monitor->dnsConfig->expected_values ?? [],
                'match_mode' => $monitor->dnsConfig->match_mode->value,
                'last_answers' => $monitor->dnsConfig->last_answers,
                'last_resolved_at' => $monitor->dnsConfig->last_resolved_at?->toIso8601String(),
            ],
            'domain' => self::domain($monitor),
            'certificate' => $monitor->type === MonitorType::Http && $monitor->tlsCertificate !== null
                ? self::certificate($monitor->tlsCertificate)
                : null,
            'notification_channel_ids' => $monitor->notificationChannels()->pluck('notification_channels.id')->all(),
            'escalation_policy_id' => $monitor->escalation_policy_id,
            'tags' => $monitor->tags()->pluck('tags.name')->values()->all(),
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
     * Domain registration of an HTTP or DNS monitor (ADR-043); null for
     * other types and IP targets. A row computed for a previous host is
     * shown as not checked yet.
     *
     * @return array{host: string, domain: string|null, expires_at: string|null, registrar: string|null, checked_at: string|null, error: string|null, days_left: int|null, status: 'unknown'|'valid'|'expiring'|'expired'}|null
     */
    public static function domain(Monitor $monitor, ?CarbonImmutable $now = null): ?array
    {
        $host = $monitor->domainHost();

        if ($host === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $row = $monitor->domain !== null && $monitor->domain->host === $host ? $monitor->domain : null;
        $expiresAt = $row?->expires_at;
        $threshold = $expiresAt === null ? null : CheckCertificateExpiry::thresholdFor($expiresAt, $now, CheckCertificateExpiry::thresholds());

        return [
            'host' => $host,
            'domain' => $row?->domain,
            'expires_at' => $expiresAt?->toIso8601String(),
            'registrar' => $row?->registrar,
            'checked_at' => $row?->checked_at?->toIso8601String(),
            'error' => $row?->last_error === null ? null : (string) __($row->last_error),
            'days_left' => $expiresAt === null ? null : (int) floor($now->diffInDays($expiresAt, false)),
            'status' => match (true) {
                $expiresAt === null => 'unknown',
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
            'slow' => $check->slow,
            'duration_ms' => $check->duration_ms,
            'status_code' => $check->status_code,
            'error_code' => $check->error_code?->value,
            'error_label' => $check->error_code === null ? null : (string) __($check->error_code->label()),
            'redirects' => (int) ($check->metadata['redirects'] ?? 0),
            'in_maintenance' => $check->in_maintenance,
        ];
    }

    /**
     * Intervals shorter than the organization minimum are left out, except
     * the current interval of an edited monitor (limits apply to growth only).
     *
     * @return array<string, mixed>
     */
    public static function formOptions(Organization $organization, ?int $currentInterval = null): array
    {
        $minimumInterval = app(Quota::class)->minIntervalSeconds($organization);

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
            'escalationPolicies' => $organization->escalationPolicies()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn (EscalationPolicy $policy): array => ['id' => $policy->id, 'name' => $policy->name, 'is_default' => $policy->is_default])
                ->all(),
            'intervals' => array_values(array_filter(
                MonitorInterval::options(),
                fn (array $option): bool => $minimumInterval === null || $option['value'] >= $minimumInterval || $option['value'] === $currentInterval,
            )),
            'methods' => array_map(fn (HttpMethod $method): string => $method->value, HttpMethod::cases()),
            'contentTypes' => array_map(fn (RequestContentType $type): string => $type->value, RequestContentType::cases()),
            'keywordModes' => array_map(fn (KeywordMode $mode): string => $mode->value, KeywordMode::cases()),
            'maxBodyBytes' => SaveMonitorRequest::MAX_BODY_BYTES,
            'types' => array_map(fn (MonitorType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], SaveMonitorRequest::CREATABLE_TYPES),
            'tcpPorts' => app(ProbeSettings::class)->tcpAllowedPorts,
            'heartbeatPeriods' => HeartbeatPeriod::options(),
            'dnsRecordTypes' => array_map(fn (DnsRecordType $type): string => $type->value, DnsRecordType::cases()),
            'dnsResolvers' => array_map(fn (DnsResolverChoice $resolver): array => [
                'value' => $resolver->value,
                'label' => $resolver->label(),
            ], DnsResolverChoice::allowed()),
            'dnsMatchModes' => array_map(fn (DnsMatchMode $mode): string => $mode->value, DnsMatchMode::cases()),
            'maxDnsExpectedValues' => SaveMonitorRequest::MAX_DNS_EXPECTED_VALUES,
            'maxGraceMinutes' => SaveMonitorRequest::MAX_GRACE_MINUTES,
            'tagOptions' => self::tagOptions($organization),
            'maxTags' => Tag::MAX_PER_MONITOR,
            'maxTagLength' => Tag::MAX_LENGTH,
        ];
    }

    /**
     * Names of the organization's tags, for filters and suggestions (ADR-047).
     *
     * @return list<string>
     */
    public static function tagOptions(Organization $organization): array
    {
        return array_values($organization->tags()->orderBy('name')->pluck('name')->all());
    }
}
