<?php

namespace App\Domain\Monitoring\Support;

use App\Domain\Monitoring\Enums\MonitorType;
use Carbon\CarbonImmutable;

final class CheckSchedule
{
    /**
     * Next execution time after a scheduled slot. Missed slots (scheduler or
     * worker outage) are skipped rather than replayed in a burst; they are
     * never interpreted as downtime.
     */
    public static function next(CarbonImmutable $scheduledFor, int $intervalSeconds, CarbonImmutable $now): CarbonImmutable
    {
        $next = $scheduledFor->addSeconds($intervalSeconds);

        return $next->greaterThan($now) ? $next : $now->addSeconds($intervalSeconds)->startOfSecond();
    }

    /**
     * First evaluation after creation, an interval change or a resume.
     * Probed monitors are checked right away; a heartbeat gets a full period
     * plus its grace before a missing ping can be evaluated (ADR-025).
     */
    public static function initial(MonitorType $type, int $intervalSeconds, int $graceSeconds = 0, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();

        return $type === MonitorType::Heartbeat ? $now->addSeconds($intervalSeconds + $graceSeconds) : $now;
    }

    /**
     * A dispatch older than this is pointless to execute.
     */
    public static function staleAfterSeconds(int $intervalSeconds): int
    {
        return max($intervalSeconds, 300);
    }
}
