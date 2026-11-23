<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Http\Presenters\MonitorPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public API representation of a monitor (ADR-044). Like the interface, it
 * is an explicit allow-list: header values, request bodies, basic auth
 * passwords and heartbeat tokens never leave the server.
 *
 * @mixin Monitor
 */
class MonitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Monitor $monitor */
        $monitor = $this->resource;
        $http = $monitor->httpConfig;

        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'type' => $monitor->type->value,
            'enabled' => $monitor->enabled,
            'interval_seconds' => $monitor->interval_seconds,
            'timeout_seconds' => $monitor->type === MonitorType::Heartbeat ? null : $monitor->timeout_seconds,
            'status' => ($monitor->state->status ?? MonitorStatus::Unknown)->value,
            'status_changed_at' => $monitor->state?->last_changed_at?->toIso8601String(),
            'active_incident_id' => $monitor->state?->active_incident_id,
            'last_checked_at' => $monitor->state?->last_checked_at?->toIso8601String(),
            'next_check_at' => $monitor->next_check_at?->toIso8601String(),
            'http' => $http === null ? null : [
                'url' => $http->url,
                'method' => $http->method->value,
                'expected_statuses' => $http->expected_statuses->toExpression(),
                'follow_redirects' => $http->follow_redirects,
                'header_names' => $http->headerNames(),
                'body' => $http->hasBody() ? [
                    'content_type' => $http->body_content_type?->value,
                    'bytes' => $http->bodyBytes(),
                ] : null,
                'basic_auth_username' => $http->basicAuthUsername(),
                'keyword' => $http->keyword,
                'keyword_mode' => $http->keyword === null ? null : $http->keyword_mode?->value,
                'keyword_case_sensitive' => $http->keyword_case_sensitive,
                'latency_threshold_ms' => $http->latency_threshold_ms,
            ],
            'tcp' => $monitor->tcpConfig === null ? null : [
                'host' => $monitor->tcpConfig->host,
                'port' => $monitor->tcpConfig->port,
            ],
            'dns' => $monitor->dnsConfig === null ? null : [
                'record_name' => $monitor->dnsConfig->record_name,
                'record_type' => $monitor->dnsConfig->record_type->value,
                'resolver' => $monitor->dnsConfig->resolver->value,
                'expected_values' => $monitor->dnsConfig->expected_values ?? [],
                'match_mode' => $monitor->dnsConfig->match_mode->value,
            ],
            'heartbeat' => $monitor->heartbeatConfig === null ? null : [
                'grace_seconds' => $monitor->heartbeatConfig->grace_seconds,
                'last_ping_at' => $monitor->heartbeatConfig->last_ping_at?->toIso8601String(),
            ],
            'certificate' => $monitor->type === MonitorType::Http && $monitor->relationLoaded('tlsCertificate') && $monitor->tlsCertificate !== null
                ? MonitorPresenter::certificate($monitor->tlsCertificate)
                : null,
            'notification_channel_ids' => $this->whenLoaded(
                'notificationChannels',
                fn (): array => $monitor->notificationChannels->pluck('id')->values()->all(),
            ),
            'escalation_policy_id' => $monitor->escalation_policy_id,
            'tags' => $monitor->relationLoaded('tags') ? $monitor->tags->pluck('name')->values()->all() : $monitor->tags()->pluck('tags.name')->values()->all(),
            'created_at' => $monitor->created_at?->toIso8601String(),
            'updated_at' => $monitor->updated_at?->toIso8601String(),
        ];
    }
}
