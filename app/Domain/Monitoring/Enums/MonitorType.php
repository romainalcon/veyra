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
    case Dns = 'dns';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Http => 'HTTP(S)',
            self::Tcp => 'TCP port',
            self::Heartbeat => 'Heartbeat',
            self::Dns => 'DNS record',
        });
    }

    /**
     * Types checked by a probe on an interval (not passive heartbeats).
     *
     * @return list<self>
     */
    public static function probed(): array
    {
        return [self::Http, self::Tcp, self::Dns];
    }

    /**
     * Types whose domain registration expiry is followed (ADR-043).
     *
     * @return list<self>
     */
    public static function withDomainExpiry(): array
    {
        return [self::Http, self::Dns];
    }
}
