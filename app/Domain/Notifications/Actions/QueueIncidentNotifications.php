<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates one delivery per enabled channel attached to the incident's
 * monitor. The unique (incident, channel, event, escalation step) key makes
 * this idempotent: replayed domain events never send twice.
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
            // PagerDuty runs its own escalations: reminders would only add noise (ADR-039).
            ->when($event === NotificationEvent::Reminder, fn ($query) => $query->where('type', '!=', ChannelType::PagerDuty->value))
            ->pluck('id')
            ->all();

        return $this->queue($incident, $event, $channels, 0);
    }

    /**
     * Escalation deliveries for the channels of one step (ADR-045). Channels
     * of another organization or disabled ones are ignored.
     *
     * @param  array<int, string>  $channelIds
     * @return int number of deliveries created
     */
    public function escalate(Incident $incident, int $step, array $channelIds): int
    {
        $channels = NotificationChannel::query()
            ->where('organization_id', $incident->organization_id)
            ->where('enabled', true)
            ->whereIn('id', $channelIds)
            ->pluck('id')
            ->all();

        return $this->queue($incident, NotificationEvent::Escalated, $channels, $step);
    }

    /**
     * @param  array<int, string>  $channelIds
     */
    private function queue(Incident $incident, NotificationEvent $event, array $channelIds, int $step): int
    {
        $created = 0;

        foreach ($channelIds as $channelId) {
            $id = (string) Str::uuid7();

            $inserted = DB::table('notification_deliveries')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $incident->organization_id,
                'incident_id' => $incident->id,
                'channel_id' => $channelId,
                'event_type' => $event->value,
                'escalation_step' => $step,
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
