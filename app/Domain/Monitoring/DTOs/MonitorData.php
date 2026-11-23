<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\MonitorType;

/**
 * Validated monitor input. Exactly the target matching `type` is set.
 * `intervalSeconds` is the check interval, or the expected ping period of a
 * heartbeat.
 */
final readonly class MonitorData
{
    /**
     * @param  list<string>|null  $notificationChannelIds  null keeps the current channels (or the defaults on creation)
     * @param  bool  $escalationPolicySubmitted  false keeps the current escalation policy (ADR-045)
     * @param  string|null  $escalationPolicyId  null = the organization default policy
     */
    public function __construct(
        public MonitorType $type,
        public string $name,
        public int $intervalSeconds,
        public int $timeoutSeconds,
        public ?HttpTargetData $http = null,
        public ?TcpTargetData $tcp = null,
        public ?HeartbeatTargetData $heartbeat = null,
        public ?DnsTargetData $dns = null,
        public ?array $notificationChannelIds = null,
        public bool $escalationPolicySubmitted = false,
        public ?string $escalationPolicyId = null,
    ) {}
}
