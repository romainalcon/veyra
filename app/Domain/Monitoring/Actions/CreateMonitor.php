<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateMonitor
{
    /**
     * The first check is due immediately.
     */
    public function handle(Organization $organization, MonitorData $data): Monitor
    {
        return DB::transaction(function () use ($organization, $data): Monitor {
            $monitor = $organization->monitors()->create([
                'name' => $data->name,
                'type' => MonitorType::Http,
                'enabled' => true,
                'interval_seconds' => $data->interval->value,
                'timeout_seconds' => $data->timeoutSeconds,
                'next_check_at' => now()->startOfSecond(),
            ]);

            $monitor->httpConfig()->create([
                'url' => $data->url,
                'method' => $data->method,
                'expected_statuses' => $data->expectedStatuses,
                'follow_redirects' => $data->followRedirects,
                'headers_encrypted' => $data->headers === [] ? null : $data->headers,
            ]);

            return $monitor;
        });
    }
}
