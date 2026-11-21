<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Domain\Realtime\OrganizationUpdate;
use Carbon\CarbonImmutable;

class RecordIncidentEvent
{
    /**
     * @param  array<string, scalar|null>|null  $metadata
     */
    public function handle(
        Incident $incident,
        IncidentEventType $type,
        ?string $userId = null,
        ?string $message = null,
        ?array $metadata = null,
        ?CarbonImmutable $at = null,
    ): IncidentEvent {
        $event = $incident->events()->create([
            'type' => $type,
            'user_id' => $userId,
            'message' => $message,
            'metadata' => $metadata,
            'created_at' => $at ?? now(),
        ]);

        OrganizationUpdate::dispatch($incident->organization_id, OrganizationUpdate::INCIDENT_UPDATED, [
            'incident_id' => $incident->id,
            'monitor_id' => $incident->monitor_id,
        ]);

        return $event;
    }
}
