<?php

namespace App\Domain\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Durable record of scheduler activity, one row per minute, in MariaDB (the
 * Redis heartbeat only knows the last beat). It answers "was this Veyra
 * instance running during that whole window?" before an absence of signal is
 * interpreted as a failure.
 */
final class SchedulerBeatLog
{
    /**
     * Covers the longest heartbeat window (1 week period + 24 h grace) plus a day.
     */
    public const RETENTION_DAYS = 9;

    /**
     * Largest tolerated gap between two beats (same as the Redis heartbeat).
     */
    public const MAX_GAP_SECONDS = SchedulerHeartbeat::STALE_AFTER_SECONDS;

    public static function record(?CarbonImmutable $now = null): void
    {
        $minute = ($now ?? CarbonImmutable::now())->startOfMinute();

        DB::table('scheduler_beats')->insertOrIgnore(['beat_at' => $minute->toDateTimeString()]);
    }

    /**
     * True when beats cover [from, to] without any gap larger than MAX_GAP_SECONDS
     * (including at both edges).
     */
    public static function covers(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $row = DB::selectOne(
            'SELECT MIN(beat_at) AS first_beat, MAX(beat_at) AS last_beat, MAX(gap) AS max_gap
             FROM (
                SELECT beat_at, TIMESTAMPDIFF(SECOND, LAG(beat_at) OVER (ORDER BY beat_at), beat_at) AS gap
                FROM scheduler_beats
                WHERE beat_at BETWEEN ? AND ?
             ) AS beats',
            [
                $from->subSeconds(self::MAX_GAP_SECONDS)->toDateTimeString(),
                $to->addSeconds(self::MAX_GAP_SECONDS)->toDateTimeString(),
            ],
        );

        if ($row === null || $row->first_beat === null) {
            return false;
        }

        $first = CarbonImmutable::parse((string) $row->first_beat, 'UTC');
        $last = CarbonImmutable::parse((string) $row->last_beat, 'UTC');

        return $first->lessThanOrEqualTo($from->addSeconds(self::MAX_GAP_SECONDS))
            && $last->greaterThanOrEqualTo($to->subSeconds(self::MAX_GAP_SECONDS))
            && (int) ($row->max_gap ?? 0) <= self::MAX_GAP_SECONDS;
    }

    /**
     * @return int rows deleted (bounded batch)
     */
    public static function purge(?CarbonImmutable $now = null, int $limit = 1000): int
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays(self::RETENTION_DAYS);

        return DB::table('scheduler_beats')->where('beat_at', '<', $cutoff->toDateTimeString())->limit($limit)->delete();
    }
}
