<?php

namespace App\Domain\Monitoring\State;

use App\Domain\Monitoring\Enums\MonitorStatus;

/**
 * Pure state machine. The streaks are recomputed from the most recent checks
 * (newest first) on every evaluation, so lost, duplicated or out-of-order
 * jobs cannot corrupt counters.
 *
 * - N consecutive failures (N >= downAfter)      → DOWN
 * - M consecutive successes (M >= upAfter)       → UP
 * - failures below the threshold: UP/DEGRADED → DEGRADED, DOWN stays DOWN,
 *   UNKNOWN stays UNKNOWN
 * - successes below the threshold: UP/DEGRADED → UP (DEGRADED was never a
 *   confirmed outage), DOWN stays DOWN, UNKNOWN stays UNKNOWN
 *
 * Latency (ADR-037): slow checks are successes, so they never count towards
 * DOWN and do confirm a recovery. Where the result above is UP:
 * - N consecutive slow successes (N >= downAfter) → DEGRADED
 * - from DEGRADED, fewer than M fast successes after a slow one keep DEGRADED
 */
final class StateEvaluator
{
    /**
     * @param  list<bool>  $recentResults  success flags, newest first
     * @param  list<bool>  $slowResults  slow flags aligned with $recentResults (empty: none slow)
     */
    public static function evaluate(MonitorStatus $current, array $recentResults, StateThresholds $thresholds, array $slowResults = []): Evaluation
    {
        if ($recentResults === []) {
            return new Evaluation($current, 0, 0);
        }

        $latest = $recentResults[0];
        $streak = 0;

        foreach ($recentResults as $result) {
            if ($result !== $latest) {
                break;
            }

            $streak++;
        }

        $failures = $latest ? 0 : $streak;
        $successes = $latest ? $streak : 0;

        $status = match (true) {
            $failures >= $thresholds->downAfter => MonitorStatus::Down,
            $successes >= $thresholds->upAfter => MonitorStatus::Up,
            $failures > 0 => match ($current) {
                MonitorStatus::Up, MonitorStatus::Degraded => MonitorStatus::Degraded,
                default => $current,
            },
            default => match ($current) {
                MonitorStatus::Up, MonitorStatus::Degraded => MonitorStatus::Up,
                default => $current,
            },
        };

        if ($status === MonitorStatus::Up && self::isSlow($current, $recentResults, $slowResults, $thresholds)) {
            $status = MonitorStatus::Degraded;
        }

        return new Evaluation($status, $failures, $successes);
    }

    /**
     * @param  list<bool>  $results
     * @param  list<bool>  $slow
     */
    private static function isSlow(MonitorStatus $current, array $results, array $slow, StateThresholds $thresholds): bool
    {
        $slowStreak = 0;
        $fastStreak = 0;

        foreach ($results as $index => $success) {
            $isSlow = $success && ($slow[$index] ?? false);

            if (! $success || $isSlow !== ($slow[0] ?? false)) {
                break;
            }

            $isSlow ? $slowStreak++ : $fastStreak++;
        }

        if ($slowStreak >= $thresholds->downAfter) {
            return true;
        }

        // Hysteresis: leaving a latency degradation needs `upAfter` fast responses.
        if ($current !== MonitorStatus::Degraded || $fastStreak >= $thresholds->upAfter) {
            return false;
        }

        $before = $slowStreak + $fastStreak;

        return $slowStreak > 0 || (($results[$before] ?? false) && ($slow[$before] ?? false));
    }
}
