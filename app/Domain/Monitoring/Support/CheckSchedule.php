<?php

namespace App\Domain\Monitoring\Support;

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
     * A dispatch older than this is pointless to execute.
     */
    public static function staleAfterSeconds(int $intervalSeconds): int
    {
        return max($intervalSeconds, 300);
    }
}
