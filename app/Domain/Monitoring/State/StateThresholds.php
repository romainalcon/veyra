<?php

namespace App\Domain\Monitoring\State;

final readonly class StateThresholds
{
    public function __construct(
        public int $downAfter = 3,
        public int $upAfter = 2,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            downAfter: max(1, (int) config('veyra.monitoring.down_after_failures', 3)),
            upAfter: max(1, (int) config('veyra.monitoring.up_after_successes', 2)),
        );
    }

    /**
     * Number of recent checks needed to evaluate any transition.
     */
    public function window(): int
    {
        return max($this->downAfter, $this->upAfter);
    }
}
