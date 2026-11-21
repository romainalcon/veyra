<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorType;

/**
 * Validated monitor input. Exactly the target matching `type` is set.
 */
final readonly class MonitorData
{
    /**
     * @param  list<string>|null  $notificationChannelIds  null keeps the current channels (or the defaults on creation)
     */
    public function __construct(
        public MonitorType $type,
        public string $name,
        public MonitorInterval $interval,
        public int $timeoutSeconds,
        public ?HttpTargetData $http = null,
        public ?TcpTargetData $tcp = null,
        public ?array $notificationChannelIds = null,
    ) {}
}
