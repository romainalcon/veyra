<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;

/**
 * Must be called while holding the monitor_states row lock: the caller is
 * responsible for the "one active incident per monitor" invariant.
 */
class OpenIncident
{
    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    public function handle(Monitor $monitor, CarbonImmutable $startedAt, ?string $cause): Incident
    {
        $incident = Incident::create([
            'organization_id' => $monitor->organization_id,
            'monitor_id' => $monitor->id,
            'status' => IncidentStatus::Open,
            'started_at' => $startedAt,
            'cause' => $cause === null ? null : mb_substr($cause, 0, 255),
        ]);

        $this->recordIncidentEvent->handle($incident, IncidentEventType::Opened, message: $cause, at: $startedAt);

        IncidentOpened::dispatch($incident->id);

        return $incident;
    }
}
