<?php

namespace App\Domain\Monitoring\Heartbeats;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\HeartbeatToken;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Replaces the ping token; the previous ping URL stops working immediately.
 */
class RegenerateHeartbeatToken
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return string the new plain token, to be shown once
     */
    public function handle(Monitor $monitor): string
    {
        if ($monitor->type !== MonitorType::Heartbeat || $monitor->heartbeatConfig === null) {
            throw new LogicException('Only heartbeat monitors have a ping token.');
        }

        $token = HeartbeatToken::generate();

        $config = $monitor->heartbeatConfig;

        DB::transaction(function () use ($monitor, $config, $token): void {
            $config->update(['token_hash' => HeartbeatToken::hash($token)]);

            $this->audit->record($monitor->organization_id, AuditAction::PingTokenRegenerated, AuditSnapshot::monitorSubject($monitor));
        });

        return $token;
    }
}
