<?php

namespace App\Domain\Monitoring\Heartbeats;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\HeartbeatToken;
use LogicException;

/**
 * Replaces the ping token; the previous ping URL stops working immediately.
 */
class RegenerateHeartbeatToken
{
    /**
     * @return string the new plain token, to be shown once
     */
    public function handle(Monitor $monitor): string
    {
        if ($monitor->type !== MonitorType::Heartbeat || $monitor->heartbeatConfig === null) {
            throw new LogicException('Only heartbeat monitors have a ping token.');
        }

        $token = HeartbeatToken::generate();

        $monitor->heartbeatConfig->update(['token_hash' => HeartbeatToken::hash($token)]);

        return $token;
    }
}
