<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Kind of check a monitor performs. Every type goes through the same outbox,
 * check history and confirmed-state machine; only the probe and its
 * configuration differ (ADR-024).
 */
enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Heartbeat = 'heartbeat';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S)',
            self::Tcp => 'TCP port',
            self::Heartbeat => 'Heartbeat',
        };
    }
}
