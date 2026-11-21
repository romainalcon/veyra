<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\CheckSchedule;
use Carbon\CarbonImmutable;
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
            $intervalChanged = $monitor->interval_seconds !== $data->intervalSeconds;

            $monitor->update([
                'name' => $data->name,
                'interval_seconds' => $data->intervalSeconds,
                'timeout_seconds' => $data->timeoutSeconds,
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
                MonitorType::Heartbeat => $monitor->heartbeatConfig()->update([
                    'grace_seconds' => $this->graceSeconds($data),
                ]),
            };

            if ($monitor->enabled && $monitor->type === MonitorType::Heartbeat) {
                // The deadline follows the new period and grace from the last ping.
                $monitor->update(['next_check_at' => $this->heartbeatDeadline($monitor, $this->graceSeconds($data))]);
            } elseif ($monitor->enabled && $intervalChanged) {
                // Apply a new interval from now rather than waiting for an old, longer slot.
                $monitor->update(['next_check_at' => CheckSchedule::initial($monitor->type, $data->intervalSeconds)]);
            }

            if ($data->notificationChannelIds !== null) {
                $this->syncMonitorChannels->handle($monitor, $data->notificationChannelIds);
            }

            return $monitor;
        });
    }

    private function graceSeconds(MonitorData $data): int
    {
        return ($data->heartbeat ?? throw new LogicException('Missing heartbeat settings.'))->graceSeconds;
    }

    private function heartbeatDeadline(Monitor $monitor, int $graceSeconds): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $lastPing = $monitor->heartbeatConfig?->last_ping_at;

        if ($lastPing === null) {
            return CheckSchedule::initial(MonitorType::Heartbeat, $monitor->interval_seconds, $graceSeconds, $now);
        }

        $deadline = $lastPing->addSeconds($monitor->interval_seconds + $graceSeconds);

        return $deadline->greaterThan($now) ? $deadline : $now;
    }
}
