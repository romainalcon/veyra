<?php

namespace App\Domain\Monitoring\Events;

use App\Domain\Monitoring\Enums\MonitorStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MonitorStateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $monitorId,
        public readonly MonitorStatus $from,
        public readonly MonitorStatus $to,
    ) {}
}
