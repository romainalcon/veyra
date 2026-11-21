<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Models\Monitor;
use Illuminate\Support\Facades\DB;

class UpdateMonitor
{
    public function __construct(private readonly SyncMonitorChannels $syncMonitorChannels) {}

    public function handle(Monitor $monitor, MonitorData $data): Monitor
    {
        return DB::transaction(function () use ($monitor, $data): Monitor {
            $intervalChanged = $monitor->interval_seconds !== $data->interval->value;

            $monitor->update([
                'name' => $data->name,
                'interval_seconds' => $data->interval->value,
                'timeout_seconds' => $data->timeoutSeconds,
                // Apply a new interval from now rather than waiting for an old, longer slot.
                ...($intervalChanged && $monitor->enabled ? ['next_check_at' => now()->startOfSecond()] : []),
            ]);

            $config = [
                'url' => $data->url,
                'method' => $data->method,
                'expected_statuses' => $data->expectedStatuses,
                'follow_redirects' => $data->followRedirects,
            ];

            if ($data->headers !== null) {
                $config['headers_encrypted'] = $data->headers === [] ? null : $data->headers;
            }

            $monitor->httpConfig()->updateOrCreate(['monitor_id' => $monitor->id], $config);

            if ($data->notificationChannelIds !== null) {
                $this->syncMonitorChannels->handle($monitor, $data->notificationChannelIds);
            }

            return $monitor;
        });
    }
}
