<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Events\IncidentAcknowledged;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Organizations\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcknowledgeIncident
{
    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    /**
     * Idempotent: acknowledging twice records a single event. An API token
     * (ADR-044) is recorded in the event metadata, not as a user.
     *
     * @return bool whether the incident was acknowledged by this call
     */
    public function handle(Incident $incident, User|ApiToken $actor): bool
    {
        return DB::transaction(function () use ($incident, $actor): bool {
            $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($locked->acknowledged_at !== null) {
                return false;
            }

            $userId = $actor instanceof User ? $actor->id : null;

            $locked->update(['acknowledged_at' => now(), 'acknowledged_by' => $userId]);

            $this->recordIncidentEvent->handle(
                $locked,
                IncidentEventType::Acknowledged,
                userId: $userId,
                metadata: $actor instanceof ApiToken ? ['api_token_id' => $actor->id, 'api_token_name' => $actor->name] : null,
            );

            IncidentAcknowledged::dispatch($locked->id, $userId);

            return true;
        });
    }
}
