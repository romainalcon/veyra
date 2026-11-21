<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

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

    /**
     * `durationSeconds` carries the time left before expiry.
     */
    public static function forCertificateAlert(TlsCertificateAlert $alert): NotificationMessage
    {
        $alert->loadMissing(['monitor', 'organization']);

        return new NotificationMessage(
            event: $alert->isExpired() ? NotificationEvent::CertificateExpired : NotificationEvent::CertificateExpiring,
            organizationName: $alert->organization->name,
            monitorName: $alert->monitor->name ?? 'Deleted monitor',
            cause: null,
            startedAt: null,
            resolvedAt: null,
            durationSeconds: $alert->isExpired() ? null : max(0, (int) CarbonImmutable::now()->diffInSeconds($alert->not_after)),
            acknowledgedBy: null,
            url: route('organizations.monitors.show', [$alert->organization->slug, $alert->monitor_id]),
            certificateExpiresAt: $alert->not_after->toDateTimeString(),
            certificateIssuer: $alert->monitor->tlsCertificate?->issuer,
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
