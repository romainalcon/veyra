<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates one delivery per enabled channel attached to the alert's monitor.
 * The unique (certificate_alert_id, channel_id) key makes this idempotent.
 */
class QueueCertificateNotifications
{
    /**
     * @return int number of deliveries created
     */
    public function handle(string $alertId): int
    {
        $alert = TlsCertificateAlert::query()->findOrFail($alertId);
        $event = $alert->isExpired() ? NotificationEvent::CertificateExpired : NotificationEvent::CertificateExpiring;

        $channels = NotificationChannel::query()
            ->where('organization_id', $alert->organization_id)
            ->where('enabled', true)
            ->whereHas('monitors', fn ($query) => $query->where('monitors.id', $alert->monitor_id))
            ->pluck('id');

        $created = 0;

        foreach ($channels as $channelId) {
            $id = (string) Str::uuid7();

            $inserted = DB::table('notification_deliveries')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $alert->organization_id,
                'certificate_alert_id' => $alert->id,
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
