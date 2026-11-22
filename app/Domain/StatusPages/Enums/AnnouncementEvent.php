<?php

namespace App\Domain\StatusPages\Enums;

/**
 * What a status page announces to its confirmed subscribers (ADR-036).
 */
enum AnnouncementEvent: string
{
    case IncidentOpened = 'incident_opened';
    case IncidentUpdated = 'incident_updated';
    case IncidentResolved = 'incident_resolved';
    case MaintenanceScheduled = 'maintenance_scheduled';
}
