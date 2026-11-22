<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\StatusPages\Enums\AnnouncementEvent;
use App\Domain\StatusPages\Jobs\SendStatusPageAnnouncement;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates one announcement per published status page (with subscriptions
 * enabled) that shows the affected monitors. Payloads hold whitelisted
 * public fields only: the component's public name, public update messages,
 * maintenance title and description. The unique (page, subject, event) key
 * makes replayed domain events harmless.
 */
class QueueStatusPageAnnouncements
{
    public function forIncident(Incident $incident, AnnouncementEvent $event): int
    {
        $at = $event === AnnouncementEvent::IncidentResolved ? $incident->resolved_at : $incident->started_at;

        return $this->announce(
            $incident->organization_id,
            [$incident->monitor_id],
            $incident->id,
            $event,
            fn (Collection $names): array => [
                'component' => (string) $names->first(),
                'started_at' => $incident->started_at->toIso8601String(),
                'at' => ($at ?? now())->toIso8601String(),
            ],
        );
    }

    public function forPublicUpdate(IncidentEvent $update): int
    {
        if ($update->type !== IncidentEventType::PublicUpdate) {
            return 0;
        }

        $incident = Incident::query()->find($update->incident_id);

        if ($incident === null) {
            return 0;
        }

        return $this->announce(
            $incident->organization_id,
            [$incident->monitor_id],
            $update->id,
            AnnouncementEvent::IncidentUpdated,
            fn (Collection $names): array => [
                'component' => (string) $names->first(),
                'message' => $update->message,
                'at' => $update->created_at->toIso8601String(),
            ],
        );
    }

    public function forMaintenance(MaintenanceWindow $window): int
    {
        if ($window->isCancelled() || $window->hasEnded(now())) {
            return 0;
        }

        return $this->announce(
            $window->organization_id,
            array_values($window->monitors()->pluck('monitors.id')->map(fn (mixed $id): string => (string) $id)->all()),
            $window->id,
            AnnouncementEvent::MaintenanceScheduled,
            fn (Collection $names): array => [
                'title' => $window->title,
                'description' => $window->description,
                'starts_at' => $window->starts_at->toIso8601String(),
                'ends_at' => $window->ends_at->toIso8601String(),
                'components' => $names->unique()->values()->all(),
            ],
        );
    }

    /**
     * @param  list<string>  $monitorIds
     * @param  callable(Collection<int, string>): array<string, mixed>  $payload  receives the public names on one page
     */
    private function announce(string $organizationId, array $monitorIds, string $subjectId, AnnouncementEvent $event, callable $payload): int
    {
        if ($monitorIds === []) {
            return 0;
        }

        $pages = StatusPage::query()
            ->where('organization_id', $organizationId)
            ->where('is_published', true)
            ->where('subscriptions_enabled', true)
            ->with(['components' => fn ($query) => $query->whereIn('monitor_id', $monitorIds)])
            ->whereHas('components', fn ($query) => $query->whereIn('monitor_id', $monitorIds))
            ->get();

        $created = 0;

        foreach ($pages as $page) {
            $names = $page->components->map(fn (StatusPageComponent $component): string => $component->display_name)->values();
            $id = (string) Str::uuid7();

            $inserted = DB::table('status_page_announcements')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $organizationId,
                'status_page_id' => $page->id,
                'subject_id' => $subjectId,
                'event' => $event->value,
                'payload' => json_encode($payload($names), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                SendStatusPageAnnouncement::dispatch($id)->afterCommit();
                $created++;
            }
        }

        return $created;
    }
}
