<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An incident (ADR-044). The cause is the stored English text; members'
 * names and e-mail addresses are not exposed, events only tell whether a
 * member or an API token acted.
 *
 * @mixin Incident
 */
class IncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Incident $incident */
        $incident = $this->resource;

        return [
            'id' => $incident->id,
            'monitor_id' => $incident->monitor_id,
            'status' => $incident->status->value,
            'cause' => $incident->cause,
            'started_at' => $incident->started_at->toIso8601String(),
            'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'duration_seconds' => $incident->durationSeconds(),
            'events' => $this->whenLoaded('events', fn (): array => $incident->events
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (IncidentEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'actor' => match (true) {
                        $event->user_id !== null => 'member',
                        isset($event->metadata['api_token_id']) => 'api_token',
                        default => 'system',
                    },
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
