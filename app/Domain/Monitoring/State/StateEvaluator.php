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
 */
final class StateEvaluator
{
    /**
     * @param  list<bool>  $recentResults  success flags, newest first
     */
    public static function evaluate(MonitorStatus $current, array $recentResults, StateThresholds $thresholds): Evaluation
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

        return new Evaluation($status, $failures, $successes);
    }
}
