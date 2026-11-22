<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Support\CheckSchedule;
use App\Domain\Monitoring\Support\HeartbeatToken;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateMonitor
{
    public function __construct(
        private readonly SyncMonitorChannels $syncMonitorChannels,
        private readonly Quota $quota,
    ) {}

    /**
     * Probed monitors are due immediately; a heartbeat is first evaluated
     * after one period plus its grace. A new monitor is active, so it counts
     * against the active monitor limit.
     *
     * @param  string|null  $pingToken  plain ping token of a heartbeat (only its hash is stored)
     */
    public function handle(Organization $organization, MonitorData $data, ?string $pingToken = null): Monitor
    {
        return DB::transaction(function () use ($organization, $data, $pingToken): Monitor {
            $this->quota->ensureCanAdd($organization, Feature::Monitors);

            $graceSeconds = $data->heartbeat->graceSeconds ?? 0;

            $monitor = $organization->monitors()->create([
                'name' => $data->name,
                'type' => $data->type,
                'enabled' => true,
                'interval_seconds' => $data->intervalSeconds,
                'timeout_seconds' => $data->timeoutSeconds,
                'next_check_at' => CheckSchedule::initial($data->type, $data->intervalSeconds, $graceSeconds),
            ]);

            MonitorState::ensureFor($monitor->id);

            match ($data->type) {
                MonitorType::Http => $monitor->httpConfig()->create(
                    ($data->http ?? throw new LogicException('Missing HTTP target.'))->toConfig() + ['headers_encrypted' => null, 'body_encrypted' => null, 'body_content_type' => null, 'basic_auth_encrypted' => null],
                ),
                MonitorType::Tcp => $monitor->tcpConfig()->create(
                    ($data->tcp ?? throw new LogicException('Missing TCP target.'))->toConfig(),
                ),
                MonitorType::Dns => $monitor->dnsConfig()->create(
                    ($data->dns ?? throw new LogicException('Missing DNS target.'))->toConfig(),
                ),
                MonitorType::Heartbeat => $monitor->heartbeatConfig()->create([
                    'token_hash' => HeartbeatToken::hash($pingToken ?? throw new LogicException('Missing ping token.')),
                    'grace_seconds' => $graceSeconds,
                ]),
            };

            $this->syncMonitorChannels->handle($monitor, $data->notificationChannelIds);

            return $monitor;
        });
    }
}
