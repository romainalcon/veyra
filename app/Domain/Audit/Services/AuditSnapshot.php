<?php

namespace App\Domain\Audit\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\UrlRedactor;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\EscalationPolicyStep;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageComponent;

/**
 * Allow-listed, secret-free representations of audited resources
 * (ADR-046). Header values, request bodies, basic auth credentials,
 * channel configurations (webhook URLs, tokens, recipients), signing
 * secrets, ping and API tokens and URL query strings are never included:
 * only whether a secret is set. Snapshots read the stored rows, so one taken
 * after a save reflects what was committed.
 */
final class AuditSnapshot
{
    public static function monitorSubject(Monitor $monitor): AuditSubject
    {
        return new AuditSubject('monitor', $monitor->id, $monitor->name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function monitor(Monitor $monitor): array
    {
        $monitor = Monitor::query()->withTrashed()->with(['httpConfig', 'tcpConfig', 'dnsConfig', 'heartbeatConfig'])->find($monitor->id) ?? $monitor;
        $http = $monitor->httpConfig;

        return array_filter([
            'name' => $monitor->name,
            'type' => $monitor->type,
            'enabled' => $monitor->enabled,
            'interval_seconds' => $monitor->interval_seconds,
            'timeout_seconds' => $monitor->timeout_seconds,
            'tags' => $monitor->tags()->orderBy('name')->pluck('name')->all(),
            'notification_channel_ids' => $monitor->notificationChannels()->orderBy('notification_channels.id')->pluck('notification_channels.id')->all(),
            'escalation_policy_id' => $monitor->escalation_policy_id,
            ...($http === null ? [] : [
                'url' => UrlRedactor::redact($http->url),
                'url_has_query' => parse_url($http->url, PHP_URL_QUERY) !== null,
                'method' => $http->method,
                'expected_statuses' => $http->expected_statuses->toExpression(),
                'follow_redirects' => $http->follow_redirects,
                'header_names' => $http->headerNames(),
                'body_set' => $http->hasBody(),
                'basic_auth_set' => $http->basicAuthUsername() !== null,
                'keyword' => $http->keyword,
                'keyword_mode' => $http->keyword === null ? null : $http->keyword_mode,
                'latency_threshold_ms' => $http->latency_threshold_ms,
            ]),
            ...($monitor->tcpConfig === null ? [] : [
                'host' => $monitor->tcpConfig->host,
                'port' => $monitor->tcpConfig->port,
            ]),
            ...($monitor->dnsConfig === null ? [] : [
                'record_name' => $monitor->dnsConfig->record_name,
                'record_type' => $monitor->dnsConfig->record_type,
                'resolver' => $monitor->dnsConfig->resolver,
                'expected_values' => $monitor->dnsConfig->expected_values ?? [],
                'match_mode' => $monitor->dnsConfig->match_mode,
            ]),
            ...($monitor->heartbeatConfig === null ? [] : [
                'grace_seconds' => $monitor->heartbeatConfig->grace_seconds,
            ]),
        ], fn (mixed $value): bool => $value !== null);
    }

    public static function channelSubject(NotificationChannel $channel): AuditSubject
    {
        return new AuditSubject('notification_channel', $channel->id, $channel->name);
    }

    /**
     * The summary is the redacted form shown in the interface (a host, a
     * topic, a chat id or the last four characters of a secret).
     *
     * @return array<string, mixed>
     */
    public static function channel(NotificationChannel $channel): array
    {
        return [
            'name' => $channel->name,
            'type' => $channel->type,
            'enabled' => $channel->enabled,
            'is_default' => $channel->is_default,
            'summary' => $channel->summary(),
        ];
    }

    public static function escalationPolicySubject(EscalationPolicy $policy): AuditSubject
    {
        return new AuditSubject('escalation_policy', $policy->id, $policy->name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function escalationPolicy(EscalationPolicy $policy): array
    {
        $steps = $policy->steps()->with('channels:id')->get();

        return [
            'name' => $policy->name,
            'is_default' => $policy->is_default,
            'steps' => $steps->map(fn (EscalationPolicyStep $step): array => [
                'delay_minutes' => $step->delay_minutes,
                'channel_ids' => $step->channels->pluck('id')->sort()->values()->all(),
            ])->all(),
        ];
    }

    public static function statusPageSubject(StatusPage $statusPage): AuditSubject
    {
        return new AuditSubject('status_page', $statusPage->id, $statusPage->title);
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusPage(StatusPage $statusPage): array
    {
        return [
            'title' => $statusPage->title,
            'slug' => $statusPage->slug,
            'description' => $statusPage->description,
            'is_published' => $statusPage->is_published,
            'show_uptime' => $statusPage->show_uptime,
            'accent_color' => $statusPage->accent_color,
            'subscriptions_enabled' => $statusPage->subscriptions_enabled,
            'custom_domain' => $statusPage->custom_domain,
            'components' => $statusPage->components()->get()->map(fn (StatusPageComponent $component): array => [
                'monitor_id' => $component->monitor_id,
                'display_name' => $component->display_name,
                'group_name' => $component->group_name,
            ])->all(),
        ];
    }

    public static function organizationSubject(Organization $organization): AuditSubject
    {
        return new AuditSubject('organization', $organization->id, $organization->name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function organization(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'locale' => $organization->locale,
            'timezone' => $organization->timezone,
            'require_two_factor' => $organization->require_two_factor,
        ];
    }

    public static function memberSubject(OrganizationMember $member): AuditSubject
    {
        return new AuditSubject('member', $member->id, "{$member->user->name} <{$member->user->email}>");
    }

    public static function invitationSubject(OrganizationInvitation $invitation): AuditSubject
    {
        return new AuditSubject('invitation', $invitation->id, $invitation->email);
    }

    public static function apiTokenSubject(ApiToken $token): AuditSubject
    {
        return new AuditSubject('api_token', $token->id, $token->name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function apiToken(ApiToken $token): array
    {
        return [
            'name' => $token->name,
            'ability' => $token->ability,
            'token' => 'veyra_…'.$token->last_four,
            'expires_at' => $token->expires_at,
        ];
    }
}
