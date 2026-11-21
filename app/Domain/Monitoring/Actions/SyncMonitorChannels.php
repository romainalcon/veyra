<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\NotificationChannel;

class SyncMonitorChannels
{
    /**
     * Attach only channels of the monitor's own organization (defence in depth:
     * the Form Request validates the same constraint).
     *
     * @param  list<string>|null  $channelIds  null selects the organization's default channels
     */
    public function handle(Monitor $monitor, ?array $channelIds): void
    {
        $query = NotificationChannel::query()->where('organization_id', $monitor->organization_id);

        $ids = $channelIds === null
            ? $query->where('is_default', true)->pluck('id')->all()
            : $query->whereIn('id', $channelIds)->pluck('id')->all();

        $monitor->notificationChannels()->sync($ids);
    }
}
