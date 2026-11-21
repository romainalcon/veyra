<?php

namespace App\Domain\Incidents\Enums;

enum IncidentEventType: string
{
    case Opened = 'opened';
    case Acknowledged = 'acknowledged';
    case Note = 'note';
    /** Message published on status pages (internal notes never are). */
    case PublicUpdate = 'public_update';
    case DataUnavailable = 'data_unavailable';
    case DataRestored = 'data_restored';
    case MaintenanceStarted = 'maintenance_started';
    case MaintenanceEnded = 'maintenance_ended';
    case Resolved = 'resolved';
    case MonitorDeleted = 'monitor_deleted';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Incident opened',
            self::Acknowledged => 'Acknowledged',
            self::Note => 'Note',
            self::PublicUpdate => 'Public update',
            self::DataUnavailable => 'Monitoring data unavailable',
            self::DataRestored => 'Monitoring data restored',
            self::MaintenanceStarted => 'Planned maintenance started',
            self::MaintenanceEnded => 'Planned maintenance ended',
            self::Resolved => 'Resolved',
            self::MonitorDeleted => 'Monitor deleted',
        };
    }

    /**
     * Event types that may appear on public status pages.
     *
     * @return list<self>
     */
    public static function publicTypes(): array
    {
        return [self::Opened, self::PublicUpdate, self::Resolved];
    }
}
