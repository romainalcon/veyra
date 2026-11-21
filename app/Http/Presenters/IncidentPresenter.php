<?php

namespace App\Http\Presenters;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;

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
            'cause' => $incident->cause,
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
            'acknowledged_by' => $incident->acknowledger?->name,
            'events' => $incident->events
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (IncidentEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'label' => $event->type->label(),
                    'message' => $event->message,
                    'user' => $event->user?->name,
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
