<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Incidents\Events\IncidentAcknowledged;
use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Events\IncidentResolved;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Actions\QueueIncidentNotifications;
use App\Domain\Notifications\Enums\NotificationEvent;

class QueueNotificationsOnIncidentEvent
{
    public function __construct(private readonly QueueIncidentNotifications $queueIncidentNotifications) {}

    public function handle(IncidentOpened|IncidentResolved|IncidentAcknowledged $event): void
    {
        $incident = Incident::query()->find($event->incidentId);

        if ($incident === null) {
            return;
        }

        $this->queueIncidentNotifications->handle($incident, match (true) {
            $event instanceof IncidentOpened => NotificationEvent::Opened,
            $event instanceof IncidentResolved => NotificationEvent::Resolved,
            $event instanceof IncidentAcknowledged => NotificationEvent::Acknowledged,
        });
    }
}
