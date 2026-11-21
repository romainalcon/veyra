<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Probes\Contracts\MonitorProbe;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the probe of a monitor type. Types without a probe yet return
 * null: their dispatches are skipped, never recorded as failures.
 */
final class ProbeRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(MonitorType $type): ?MonitorProbe
    {
        return match ($type) {
            MonitorType::Http => $this->container->make(HttpMonitorProbe::class),
            MonitorType::Tcp => $this->container->make(TcpProbe::class),
            MonitorType::Heartbeat => null,
        };
    }
}
