<?php

namespace App\Domain\Monitoring\Transfer;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Builds the versioned export document (ADR-047). Secrets are never
 * exported: header values and names, request bodies, basic auth
 * credentials and heartbeat ping tokens are left out and listed in
 * `excluded`, so the importer can tell what must be entered again.
 * Organization-specific references (notification channels, escalation
 * policies) are left out as well: imported monitors get the defaults of
 * the organization they are imported into.
 */
final class MonitorExport
{
    public const FORMAT = 'veyra.monitors';

    public const VERSION = 1;

    /**
     * @return array{format: string, version: int, exported_at: string, monitors: list<array<string, mixed>>}
     */
    public static function document(Organization $organization, ?string $tag = null): array
    {
        $monitors = $organization->monitors()
            ->with(['httpConfig', 'tcpConfig', 'dnsConfig', 'heartbeatConfig', 'tags'])
            ->when($tag !== null, fn ($query) => $query->taggedWith((string) $tag))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => CarbonImmutable::now()->toIso8601String(),
            'monitors' => array_values($monitors->map(fn (Monitor $monitor): array => self::entry($monitor))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(Monitor $monitor): array
    {
        $entry = [
            'name' => $monitor->name,
            'type' => $monitor->type->value,
            'enabled' => $monitor->enabled,
            'interval_seconds' => $monitor->interval_seconds,
            ...($monitor->type === MonitorType::Heartbeat ? [] : ['timeout_seconds' => $monitor->timeout_seconds]),
            'tags' => $monitor->tags->pluck('name')->values()->all(),
        ];

        $http = $monitor->httpConfig;
        $excluded = [];

        match ($monitor->type) {
            MonitorType::Http => $entry['http'] = $http === null ? null : [
                'url' => $http->url,
                'method' => $http->method->value,
                'expected_statuses' => $http->expected_statuses->toExpression(),
                'follow_redirects' => $http->follow_redirects,
                'keyword' => $http->keyword,
                'keyword_mode' => $http->keyword === null ? null : $http->keyword_mode?->value,
                'keyword_case_sensitive' => $http->keyword_case_sensitive,
                'latency_threshold_ms' => $http->latency_threshold_ms,
            ],
            MonitorType::Tcp => $entry['tcp'] = $monitor->tcpConfig === null ? null : [
                'host' => $monitor->tcpConfig->host,
                'port' => $monitor->tcpConfig->port,
            ],
            MonitorType::Dns => $entry['dns'] = $monitor->dnsConfig === null ? null : [
                'record_name' => $monitor->dnsConfig->record_name,
                'record_type' => $monitor->dnsConfig->record_type->value,
                'resolver' => $monitor->dnsConfig->resolver->value,
                'expected_values' => $monitor->dnsConfig->expected_values ?? [],
                'match_mode' => $monitor->dnsConfig->match_mode->value,
            ],
            MonitorType::Heartbeat => $entry['heartbeat'] = [
                'grace_minutes' => intdiv($monitor->heartbeatConfig->grace_seconds ?? 0, 60),
            ],
        };

        if ($http !== null) {
            $excluded = array_keys(array_filter([
                'headers' => $http->headerNames() !== [],
                'body' => $http->hasBody(),
                'basic_auth' => $http->basicAuthUsername() !== null,
            ]));
        }

        if ($monitor->type === MonitorType::Heartbeat) {
            $excluded[] = 'ping_token';
        }

        return $excluded === [] ? $entry : [...$entry, 'excluded' => $excluded];
    }
}
