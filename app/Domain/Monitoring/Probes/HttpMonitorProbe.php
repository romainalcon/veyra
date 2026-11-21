<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\MonitorProbe;
use Carbon\CarbonImmutable;
use LogicException;

final class HttpMonitorProbe implements MonitorProbe
{
    public function __construct(private readonly HttpProbe $http) {}

    public function canProbe(Monitor $monitor): bool
    {
        return $monitor->httpConfig !== null;
    }

    public function probe(Monitor $monitor, CarbonImmutable $scheduledFor): ProbeResult
    {
        $config = $monitor->httpConfig ?? throw new LogicException('HTTP monitor without configuration.');

        return $this->http->run($config, $monitor->timeout_seconds);
    }
}
