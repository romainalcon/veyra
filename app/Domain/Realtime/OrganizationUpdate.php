<?php

namespace App\Domain\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lightweight real-time signal on the organization's private channel.
 *
 * Payloads carry identifiers only: clients reload the data over HTTP, which
 * remains the source of truth (and enforces authorization again).
 */
class OrganizationUpdate implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const MONITOR_CHECKED = 'monitor.checked';

    public const MONITOR_STATE_CHANGED = 'monitor.state-changed';

    public const INCIDENT_UPDATED = 'incident.updated';

    public const NOTIFICATION_DELIVERED = 'notification.delivered';

    /**
     * @param  array<string, string>  $payload  identifiers and enum values only
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('organizations.'.$this->organizationId);
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    public function broadcastQueue(): string
    {
        return 'incidents';
    }
}
