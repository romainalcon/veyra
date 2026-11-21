<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Organizations\Models\Organization;

final class MessageFactory
{
    public static function forIncident(Incident $incident, NotificationEvent $event): NotificationMessage
    {
        $incident->loadMissing(['monitor', 'organization', 'acknowledger']);

        return new NotificationMessage(
            event: $event,
            organizationName: $incident->organization->name,
            monitorName: $incident->monitor->name ?? 'Deleted monitor',
            cause: $incident->cause,
            startedAt: $incident->started_at->toDateTimeString(),
            resolvedAt: $incident->resolved_at?->toDateTimeString(),
            durationSeconds: $incident->durationSeconds(),
            acknowledgedBy: $incident->acknowledger?->name,
            url: route('organizations.incidents.show', [$incident->organization->slug, $incident->id]),
        );
    }

    public static function test(Organization $organization): NotificationMessage
    {
        return new NotificationMessage(
            event: NotificationEvent::Test,
            organizationName: $organization->name,
            monitorName: 'Test notification',
            cause: null,
            startedAt: null,
            resolvedAt: null,
            durationSeconds: null,
            acknowledgedBy: null,
            url: route('organizations.notification-channels.index', $organization->slug),
        );
    }
}
