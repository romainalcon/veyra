<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Confirmed monitor state, derived from several raw check results.
 */
enum MonitorStatus: string
{
    case Up = 'up';
    /** Recent failures below the DOWN confirmation threshold. */
    case Degraded = 'degraded';
    case Down = 'down';
    /** No (recent) data: never interpreted as downtime. */
    case Unknown = 'unknown';
    /** Planned maintenance: checks keep running but never confirm a state. */
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Up',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
            self::Unknown => 'Unknown',
            self::Maintenance => 'Maintenance',
        };
    }
}
