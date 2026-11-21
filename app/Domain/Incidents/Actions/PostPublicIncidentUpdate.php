<?php

namespace App\Domain\Incidents\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Models\User;

/**
 * Publishes a message on the status pages showing the incident's monitor.
 * Internal notes stay private; only these updates are public.
 */
class PostPublicIncidentUpdate
{
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    public function handle(Incident $incident, User $user, string $message): IncidentEvent
    {
        return $this->recordIncidentEvent->handle($incident, IncidentEventType::PublicUpdate, userId: $user->id, message: trim($message));
    }
}
