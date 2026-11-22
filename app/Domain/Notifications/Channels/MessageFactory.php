<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Localization\SupportedLocales;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class MessageFactory
{
    public static function forIncident(Incident $incident, NotificationEvent $event, ?string $deliveryId = null): NotificationMessage
    {
        $incident->loadMissing(['monitor', 'organization', 'acknowledger']);

        $occurredAt = match ($event) {
            NotificationEvent::Resolved => $incident->resolved_at,
            NotificationEvent::Acknowledged => $incident->acknowledged_at,
            NotificationEvent::Reminder => $incident->reminder_sent_at,
            default => $incident->started_at,
        } ?? CarbonImmutable::now();

        return new NotificationMessage(
            event: $event,
            organizationName: $incident->organization->name,
            monitorName: $incident->monitor->name ?? (string) __('Deleted monitor', [], SupportedLocales::resolve($incident->organization->locale)),
            cause: ProbeErrorCode::translateCause($incident->cause, SupportedLocales::resolve($incident->organization->locale)),
            startedAt: $incident->started_at->toDateTimeString(),
            resolvedAt: $incident->resolved_at?->toDateTimeString(),
            durationSeconds: $incident->durationSeconds(),
            acknowledgedBy: $incident->acknowledger?->name,
            url: route('organizations.incidents.show', [$incident->organization->slug, $incident->id]),
            locale: SupportedLocales::resolve($incident->organization->locale),
            deliveryId: $deliveryId,
            organizationId: $incident->organization_id,
            organizationSlug: $incident->organization->slug,
            monitorId: $incident->monitor_id,
            monitorType: $incident->monitor?->type->value,
            incidentId: $incident->id,
            rawCause: $incident->cause,
            acknowledgedAt: $incident->acknowledged_at?->toDateTimeString(),
            occurredAt: $occurredAt->toDateTimeString(),
        );
    }

    /**
     * `durationSeconds` carries the time left before expiry.
     */
    public static function forCertificateAlert(TlsCertificateAlert $alert, ?string $deliveryId = null): NotificationMessage
    {
        $alert->loadMissing(['monitor', 'organization']);

        return new NotificationMessage(
            event: $alert->isExpired() ? NotificationEvent::CertificateExpired : NotificationEvent::CertificateExpiring,
            organizationName: $alert->organization->name,
            monitorName: $alert->monitor->name ?? (string) __('Deleted monitor', [], SupportedLocales::resolve($alert->organization->locale)),
            cause: null,
            startedAt: null,
            resolvedAt: null,
            durationSeconds: $alert->isExpired() ? null : max(0, (int) CarbonImmutable::now()->diffInSeconds($alert->not_after)),
            acknowledgedBy: null,
            url: route('organizations.monitors.show', [$alert->organization->slug, $alert->monitor_id]),
            certificateExpiresAt: $alert->not_after->toDateTimeString(),
            certificateIssuer: $alert->monitor->tlsCertificate?->issuer,
            locale: SupportedLocales::resolve($alert->organization->locale),
            deliveryId: $deliveryId,
            organizationId: $alert->organization_id,
            organizationSlug: $alert->organization->slug,
            monitorId: $alert->monitor_id,
            monitorType: $alert->monitor->type->value,
            occurredAt: $alert->created_at->toDateTimeString(),
        );
    }

    /**
     * A test has no delivery row: it gets a fresh identifier.
     */
    public static function test(Organization $organization): NotificationMessage
    {
        return new NotificationMessage(
            event: NotificationEvent::Test,
            organizationName: $organization->name,
            monitorName: (string) __('Test notification', [], SupportedLocales::resolve($organization->locale)),
            cause: null,
            startedAt: null,
            resolvedAt: null,
            durationSeconds: null,
            acknowledgedBy: null,
            url: route('organizations.notification-channels.index', $organization->slug),
            locale: SupportedLocales::resolve($organization->locale),
            deliveryId: (string) Str::uuid7(),
            organizationId: $organization->id,
            organizationSlug: $organization->slug,
            occurredAt: CarbonImmutable::now()->toDateTimeString(),
        );
    }
}
