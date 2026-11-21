<?php

namespace App\Domain\Monitoring\State;

use App\Domain\Monitoring\Enums\MonitorStatus;

final readonly class Evaluation
{
    public function __construct(
        public MonitorStatus $status,
        public int $consecutiveFailures,
        public int $consecutiveSuccesses,
    ) {}
}
