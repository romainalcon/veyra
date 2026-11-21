<?php

namespace App\Domain\Monitoring\State;

use App\Domain\Monitoring\Enums\MonitorType;

final readonly class StateThresholds
{
    public function __construct(
        public int $downAfter = 3,
        public int $upAfter = 2,
    ) {}

    /**
     * Instance-wide thresholds of actively probed monitors (HTTP, TCP).
     */
    public static function fromConfig(): self
    {
        return new self(
            downAfter: max(1, (int) config('veyra.monitoring.down_after_failures', 3)),
            upAfter: max(1, (int) config('veyra.monitoring.up_after_successes', 2)),
        );
    }

    /**
     * Heartbeats confirm on fewer results: one missed ping is only recorded
     * after the grace period has elapsed, which already acts as confirmation,
     * and a received ping is a direct signal of recovery (ADR-024).
     */
    public static function forType(MonitorType $type): self
    {
        return match ($type) {
            MonitorType::Http, MonitorType::Tcp => self::fromConfig(),
            MonitorType::Heartbeat => new self(
                downAfter: max(1, (int) config('veyra.monitoring.heartbeat_down_after_misses', 1)),
                upAfter: max(1, (int) config('veyra.monitoring.heartbeat_up_after_pings', 1)),
            ),
        };
    }

    /**
     * Number of recent checks needed to evaluate any transition.
     */
    public function window(): int
    {
        return max($this->downAfter, $this->upAfter);
    }
}
