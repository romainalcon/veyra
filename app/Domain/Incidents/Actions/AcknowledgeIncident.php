<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Events\IncidentAcknowledged;
use App\Domain\Incidents\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcknowledgeIncident
{
    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    /**
     * Idempotent: acknowledging twice records a single event.
     *
     * @return bool whether the incident was acknowledged by this call
     */
    public function handle(Incident $incident, User $user): bool
    {
        return DB::transaction(function () use ($incident, $user): bool {
            $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($locked->acknowledged_at !== null) {
                return false;
            }

            $locked->update(['acknowledged_at' => now(), 'acknowledged_by' => $user->id]);

            $this->recordIncidentEvent->handle($locked, IncidentEventType::Acknowledged, userId: $user->id);

            IncidentAcknowledged::dispatch($locked->id, $user->id);

            return true;
        });
    }
}
