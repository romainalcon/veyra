<?php

namespace App\Domain\Monitoring\DTOs;

final readonly class HeartbeatTargetData
{
    public function __construct(public int $graceSeconds) {}
}
