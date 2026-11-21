<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Incidents\Actions\ResolveIncident;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeleteMonitor
{
    public function __construct(private readonly ResolveIncident $resolveIncident) {}

    /**
     * Soft delete: history and incidents are kept. An open incident is closed
     * with a "monitor deleted" event. Pending dispatches are skipped by the
     * probe job.
     */
    public function handle(Monitor $monitor): void
    {
        DB::transaction(function () use ($monitor): void {
            MonitorState::ensureFor($monitor->id);
            $state = MonitorState::query()->lockForUpdate()->findOrFail($monitor->id);

            $incident = $state->active_incident_id ? Incident::query()->find($state->active_incident_id) : null;

            if ($incident !== null) {
                $this->resolveIncident->handle($incident, CarbonImmutable::now(), IncidentEventType::MonitorDeleted);
            }

            $state->update(['status' => MonitorStatus::Unknown, 'active_incident_id' => null]);

            $monitor->delete();
        });
    }
}
