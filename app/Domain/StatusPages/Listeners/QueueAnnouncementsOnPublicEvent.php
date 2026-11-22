<?php

namespace App\Domain\StatusPages\Listeners;

use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Events\IncidentResolved;
use App\Domain\Incidents\Events\PublicIncidentUpdatePosted;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Domain\Maintenance\Events\MaintenanceWindowScheduled;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\StatusPages\Actions\QueueStatusPageAnnouncements;
use App\Domain\StatusPages\Enums\AnnouncementEvent;

class QueueAnnouncementsOnPublicEvent
{
    public function __construct(private readonly QueueStatusPageAnnouncements $queueAnnouncements) {}

    public function handle(IncidentOpened|IncidentResolved|PublicIncidentUpdatePosted|MaintenanceWindowScheduled $event): void
    {
        match (true) {
            $event instanceof IncidentOpened => $this->incident($event->incidentId, AnnouncementEvent::IncidentOpened),
            $event instanceof IncidentResolved => $this->incident($event->incidentId, AnnouncementEvent::IncidentResolved),
            $event instanceof PublicIncidentUpdatePosted => $this->update($event->incidentEventId),
            $event instanceof MaintenanceWindowScheduled => $this->maintenance($event->maintenanceWindowId),
        };
    }

    private function incident(string $incidentId, AnnouncementEvent $event): void
    {
        $incident = Incident::query()->find($incidentId);

        if ($incident !== null) {
            $this->queueAnnouncements->forIncident($incident, $event);
        }
    }

    private function update(string $incidentEventId): void
    {
        $update = IncidentEvent::query()->find($incidentEventId);

        if ($update !== null) {
            $this->queueAnnouncements->forPublicUpdate($update);
        }
    }

    private function maintenance(string $windowId): void
    {
        $window = MaintenanceWindow::query()->find($windowId);

        if ($window !== null) {
            $this->queueAnnouncements->forMaintenance($window);
        }
    }
}
