<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Events\IncidentResolved;
use App\Domain\Incidents\Models\Incident;
use Carbon\CarbonImmutable;

/**
 * Must be called while holding the monitor_states row lock.
 */
class ResolveIncident
{
    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    public function handle(Incident $incident, CarbonImmutable $resolvedAt, IncidentEventType $reason = IncidentEventType::Resolved): Incident
    {
        if (! $incident->isOpen()) {
            return $incident;
        }

        $resolvedAt = $resolvedAt->lessThan($incident->started_at) ? $incident->started_at : $resolvedAt;

        $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => $resolvedAt]);

        $this->recordIncidentEvent->handle($incident, $reason, at: $resolvedAt);

        IncidentResolved::dispatch($incident->id);

        return $incident;
    }
}
