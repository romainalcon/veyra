<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Models\User;

class AddIncidentNote
{
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    public function handle(Incident $incident, User $user, string $message): IncidentEvent
    {
        return $this->recordIncidentEvent->handle($incident, IncidentEventType::Note, userId: $user->id, message: trim($message));
    }
}
