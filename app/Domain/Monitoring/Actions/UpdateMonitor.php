<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The type of a monitor is immutable: its history would not be comparable.
 */
class UpdateMonitor
{
    public function __construct(private readonly SyncMonitorChannels $syncMonitorChannels) {}

    public function handle(Monitor $monitor, MonitorData $data): Monitor
    {
        if ($data->type !== $monitor->type) {
            throw new LogicException('The type of a monitor cannot be changed.');
        }

        return DB::transaction(function () use ($monitor, $data): Monitor {
            $intervalChanged = $monitor->interval_seconds !== $data->interval->value;

            $monitor->update([
                'name' => $data->name,
                'interval_seconds' => $data->interval->value,
                'timeout_seconds' => $data->timeoutSeconds,
                // Apply a new interval from now rather than waiting for an old, longer slot.
                ...($intervalChanged && $monitor->enabled ? ['next_check_at' => now()->startOfSecond()] : []),
            ]);

            match ($monitor->type) {
                MonitorType::Http => $monitor->httpConfig()->updateOrCreate(
                    ['monitor_id' => $monitor->id],
                    ($data->http ?? throw new LogicException('Missing HTTP target.'))->toConfig(),
                ),
                MonitorType::Tcp => $monitor->tcpConfig()->updateOrCreate(
                    ['monitor_id' => $monitor->id],
                    ($data->tcp ?? throw new LogicException('Missing TCP target.'))->toConfig(),
                ),
                MonitorType::Heartbeat => throw new LogicException('Heartbeat monitors are not supported yet.'),
            };

            if ($data->notificationChannelIds !== null) {
                $this->syncMonitorChannels->handle($monitor, $data->notificationChannelIds);
            }

            return $monitor;
        });
    }
}
