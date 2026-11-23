<?php

namespace App\Http\Presenters;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\CheckEvidence;

final class IncidentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Incident $incident): array
    {
        $monitor = $incident->monitor;

        return [
            'id' => $incident->id,
            'status' => $incident->status->value,
            'cause' => ProbeErrorCode::translateCause($incident->cause),
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
            'duration_seconds' => $incident->durationSeconds(),
            'monitor' => $monitor === null ? null : [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'deleted' => $monitor->trashed(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Incident $incident): array
    {
        return [
            ...self::summary($incident),
            'evidence' => self::evidence($incident->evidence),
            'acknowledged_by' => $incident->acknowledger->name ?? self::apiTokenLabel($incident->events
                ->firstWhere('type', IncidentEventType::Acknowledged)),
            'events' => $incident->events
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (IncidentEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'label' => $event->type->label(),
                    // The opening event carries the stored (English) cause.
                    'message' => match ($event->type) {
                        IncidentEventType::Opened => ProbeErrorCode::translateCause($event->message),
                        IncidentEventType::Escalated => (string) __('Step :step of :policy, :count notification(s) queued.', [
                            'step' => (int) ($event->metadata['step'] ?? 0),
                            'policy' => (string) ($event->metadata['policy'] ?? ''),
                            'count' => (int) ($event->metadata['deliveries'] ?? 0),
                        ]),
                        default => $event->message,
                    },
                    'user' => $event->user->name ?? self::apiTokenLabel($event),
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Actions taken through the API name the token (ADR-044).
     */
    /**
     * Failure evidence of the first failing check (ADR-050). Only the member
     * incident page uses it: never the API, notifications or status pages.
     *
     * @return array{checked_at: string, status_code: int, http_version: string|null, error_code: string, error_label: string, content_type: string|null, headers: array<string, string>, body_excerpt: string|null, body_truncated: bool, body_omitted_reason: string|null}|null
     */
    private static function evidence(?CheckEvidence $evidence): ?array
    {
        if ($evidence === null) {
            return null;
        }

        return [
            'checked_at' => $evidence->checked_at->toIso8601String(),
            'status_code' => $evidence->status_code,
            'http_version' => $evidence->http_version,
            'error_code' => $evidence->error_code->value,
            'error_label' => (string) __($evidence->error_code->label()),
            'content_type' => $evidence->content_type,
            'headers' => $evidence->headers,
            'body_excerpt' => $evidence->body_excerpt,
            'body_truncated' => $evidence->body_truncated,
            'body_omitted_reason' => $evidence->body_omitted_reason,
        ];
    }

    private static function apiTokenLabel(?IncidentEvent $event): ?string
    {
        $name = $event?->metadata['api_token_name'] ?? null;

        return is_string($name) ? (string) __('API token :name', ['name' => $name]) : null;
    }
}
