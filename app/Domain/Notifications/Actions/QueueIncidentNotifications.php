<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates one delivery per enabled channel attached to the incident's
 * monitor. The unique (incident, channel, event) key makes this idempotent:
 * replayed domain events never send twice.
 */
class QueueIncidentNotifications
{
    /**
     * @return int number of deliveries created
     */
    public function handle(Incident $incident, NotificationEvent $event): int
    {
        $channels = NotificationChannel::query()
            ->where('organization_id', $incident->organization_id)
            ->where('enabled', true)
            ->whereHas('monitors', fn ($query) => $query->where('monitors.id', $incident->monitor_id))
            ->pluck('id');

        $created = 0;

        foreach ($channels as $channelId) {
            $id = (string) Str::uuid7();

            $inserted = DB::table('notification_deliveries')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $incident->organization_id,
                'incident_id' => $incident->id,
                'channel_id' => $channelId,
                'event_type' => $event->value,
                'status' => DeliveryStatus::Pending->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                SendNotificationDelivery::dispatch($id)->afterCommit();
                $created++;
            }
        }

        return $created;
    }
}
