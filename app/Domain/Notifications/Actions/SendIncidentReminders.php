<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Enums\NotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One reminder per incident, when it is still open and nobody acknowledged it.
 */
class SendIncidentReminders
{
    public function __construct(private readonly QueueIncidentNotifications $queueIncidentNotifications) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $threshold = $now->subMinutes((int) config('veyra.notifications.reminder_after_minutes', 30));

        $ids = Incident::query()
            ->where('status', IncidentStatus::Open)
            ->whereNull('acknowledged_at')
            ->whereNull('reminder_sent_at')
            ->where('started_at', '<=', $threshold)
            ->limit(500)
            ->pluck('id');

        $sent = 0;

        foreach ($ids as $id) {
            $sent += DB::transaction(function () use ($id, $now, $threshold): int {
                $incident = Incident::query()->lockForUpdate()->find((string) $id);

                if ($incident === null
                    || ! $incident->isOpen()
                    || $incident->acknowledged_at !== null
                    || $incident->reminder_sent_at !== null
                    || $incident->started_at->greaterThan($threshold)) {
                    return 0;
                }

                $incident->update(['reminder_sent_at' => $now]);
                $this->queueIncidentNotifications->handle($incident, NotificationEvent::Reminder);

                return 1;
            });
        }

        return $sent;
    }
}
