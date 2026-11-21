<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Organizations\Models\Organization;

/**
 * Raises the interval of the organization's probed monitors (HTTP, TCP)
 * that check more often than a minimum, for instance when a lower plan is
 * applied (ADR-030). Paused monitors are raised too, so that resuming them
 * respects the minimum. Heartbeat periods are passive and left unchanged.
 * The next check keeps its time; later slots follow the new interval.
 */
class RaiseMonitorIntervals
{
    /**
     * @return int number of monitors raised
     */
    public function handle(Organization $organization, int $minimumSeconds): int
    {
        return $organization->monitors()
            ->whereIn('type', [MonitorType::Http, MonitorType::Tcp])
            ->where('interval_seconds', '<', $minimumSeconds)
            ->update(['interval_seconds' => self::intervalFor($minimumSeconds)]);
    }

    /**
     * The shortest selectable interval at or above the minimum, so that the
     * monitor form still accepts the stored value.
     */
    public static function intervalFor(int $minimumSeconds): int
    {
        foreach (MonitorInterval::cases() as $interval) {
            if ($interval->value >= $minimumSeconds) {
                return $interval->value;
            }
        }

        return MonitorInterval::OneHour->value;
    }
}
