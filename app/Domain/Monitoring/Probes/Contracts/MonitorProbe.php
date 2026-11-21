<?php

namespace App\Domain\Monitoring\Probes\Contracts;

use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;

/**
 * Executes one check of a monitor. Implementations must be bounded (timeouts,
 * sizes), never throw for an unhealthy target (return a failed result) and
 * never put secrets, headers or full URLs in the result metadata.
 */
interface MonitorProbe
{
    /**
     * False when the monitor's type-specific configuration is missing; the
     * dispatch is then skipped and no check is recorded.
     */
    public function canProbe(Monitor $monitor): bool;

    /**
     * @param  CarbonImmutable  $scheduledFor  the slot (or deadline) being checked
     * @return ProbeResult|null null when no trustworthy observation can be made:
     *                          the dispatch is skipped and nothing is recorded (UNKNOWN, never DOWN)
     */
    public function probe(Monitor $monitor, CarbonImmutable $scheduledFor): ?ProbeResult;
}
