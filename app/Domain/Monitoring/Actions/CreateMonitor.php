<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateMonitor
{
    public function __construct(private readonly SyncMonitorChannels $syncMonitorChannels) {}

    /**
     * The first check is due immediately.
     */
    public function handle(Organization $organization, MonitorData $data): Monitor
    {
        return DB::transaction(function () use ($organization, $data): Monitor {
            $monitor = $organization->monitors()->create([
                'name' => $data->name,
                'type' => $data->type,
                'enabled' => true,
                'interval_seconds' => $data->interval->value,
                'timeout_seconds' => $data->timeoutSeconds,
                'next_check_at' => now()->startOfSecond(),
            ]);

            MonitorState::ensureFor($monitor->id);

            match ($data->type) {
                MonitorType::Http => $monitor->httpConfig()->create(
                    ($data->http ?? throw new LogicException('Missing HTTP target.'))->toConfig() + ['headers_encrypted' => null],
                ),
                MonitorType::Tcp => $monitor->tcpConfig()->create(
                    ($data->tcp ?? throw new LogicException('Missing TCP target.'))->toConfig(),
                ),
                MonitorType::Heartbeat => throw new LogicException('Heartbeat monitors are not supported yet.'),
            };

            $this->syncMonitorChannels->handle($monitor, $data->notificationChannelIds);

            return $monitor;
        });
    }
}
