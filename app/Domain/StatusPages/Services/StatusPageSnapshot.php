<?php

namespace App\Domain\StatusPages\Services;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentEvent;
use App\Domain\Localization\SupportedLocales;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Stats\MonitorSeries;
use App\Domain\Monitoring\Stats\StatsRange;
use App\Domain\Monitoring\Stats\UptimeCalculator;
use App\Domain\StatusPages\Enums\PublicComponentStatus;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageComponent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the public representation of a status page from an explicit
 * whitelist: public display names, public statuses, uptime and public
 * incident updates only. Monitor identifiers, URLs, causes, error codes,
 * internal names and internal notes never leave this class.
 */
class StatusPageSnapshot
{
    public const CACHE_SECONDS = 30;

    public const MAX_COMPONENTS = 50;

    public const INCIDENT_DAYS = 14;

    public const MAINTENANCE_LOOKAHEAD_DAYS = 7;

    public function __construct(
        private readonly UptimeCalculator $uptimeCalculator,
        private readonly MonitorSeries $monitorSeries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(StatusPage $statusPage): array
    {
        return Cache::remember(
            self::cacheKey($statusPage),
            self::CACHE_SECONDS,
            fn (): array => $this->build($statusPage),
        );
    }

    /**
     * The snapshot holds translated text, hence one entry per language.
     */
    public static function cacheKey(StatusPage $statusPage, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return "status-pages:{$statusPage->id}:snapshot:{$locale}";
    }

    public static function forget(StatusPage $statusPage): void
    {
        foreach (SupportedLocales::codes() as $locale) {
            Cache::forget(self::cacheKey($statusPage, $locale));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function build(StatusPage $statusPage, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        /** @var Collection<int, StatusPageComponent> $components */
        $components = $statusPage->components()
            ->whereHas('monitor', fn ($query) => $query->where('organization_id', $statusPage->organization_id))
            ->with('monitor.state')
            ->limit(self::MAX_COMPONENTS)
            ->get();

        /** @var Collection<int, Monitor> $monitors */
        $monitors = $components->map(fn (StatusPageComponent $component): ?Monitor => $component->monitor)->filter()->values();
        $namesByMonitor = $components->mapWithKeys(fn (StatusPageComponent $component): array => [$component->monitor_id => $component->display_name]);
        $uptime = $statusPage->show_uptime ? $this->uptimeCalculator->forMonitors($monitors, StatsRange::Quarter, $now) : [];

        $publicComponents = $components->map(function (StatusPageComponent $component) use ($statusPage, $uptime, $now): array {
            /** @var Monitor $monitor */
            $monitor = $component->monitor;
            $status = PublicComponentStatus::fromMonitorStatus($monitor->enabled ? ($monitor->state->status ?? MonitorStatus::Unknown) : MonitorStatus::Unknown);

            return [
                'id' => $component->id,
                'name' => $component->display_name,
                'group' => $component->group_name,
                'status' => $status->value,
                'uptime_90d' => isset($uptime[$monitor->id]) ? $uptime[$monitor->id]->uptime() : null,
                'days' => $statusPage->show_uptime
                    ? array_map(
                        fn (array $day): array => ['day' => $day['day'], 'uptime' => $day['uptime'], 'coverage' => $day['coverage']],
                        $this->monitorSeries->uptimeDays($monitor, 90, $now),
                    )
                    : [],
            ];
        });

        return [
            'page' => [
                'title' => $statusPage->title,
                'description' => $statusPage->description,
                'show_uptime' => $statusPage->show_uptime,
            ],
            'status' => PublicComponentStatus::overall(array_values(
                $publicComponents->map(fn (array $component): PublicComponentStatus => PublicComponentStatus::from($component['status']))->all(),
            ))->value,
            'groups' => $publicComponents
                ->groupBy(fn (array $component): string => (string) $component['group'])
                ->map(fn (Collection $items, string $group): array => [
                    'name' => $group === '' ? null : $group,
                    'components' => $items->map(fn (array $component): array => collect($component)->except('group')->all())->values()->all(),
                ])
                ->values()
                ->all(),
            'incidents' => $this->incidents($statusPage, $namesByMonitor, $now),
            'maintenance' => $this->maintenance($statusPage, $namesByMonitor, $now),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<string, string>  $namesByMonitor
     * @return list<array<string, mixed>>
     */
    private function incidents(StatusPage $statusPage, Collection $namesByMonitor, CarbonImmutable $now): array
    {
        if ($namesByMonitor->isEmpty()) {
            return [];
        }

        return array_values(Incident::query()
            ->where('organization_id', $statusPage->organization_id)
            ->whereIn('monitor_id', $namesByMonitor->keys()->all())
            ->where(fn ($query) => $query
                ->where('status', IncidentStatus::Open)
                ->orWhere('resolved_at', '>=', $now->subDays(self::INCIDENT_DAYS)))
            ->with(['events' => fn ($query) => $query->whereIn('type', IncidentEventType::publicTypes())])
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(function (Incident $incident) use ($statusPage, $namesByMonitor): array {
                $component = (string) $namesByMonitor->get($incident->monitor_id);

                return [
                    'key' => self::publicKey($statusPage, $incident->id),
                    'title' => __('Outage — :component', ['component' => $component]),
                    'component' => $component,
                    'status' => $incident->status === IncidentStatus::Open ? 'ongoing' : 'resolved',
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                    'updates' => $incident->events
                        ->sortBy([['created_at', 'desc'], ['id', 'desc']])
                        ->values()
                        ->map(fn (IncidentEvent $event): array => [
                            'key' => self::publicKey($statusPage, $event->id),
                            'type' => match ($event->type) {
                                IncidentEventType::Opened => 'investigating',
                                IncidentEventType::Resolved => 'resolved',
                                default => 'update',
                            },
                            'message' => $event->type === IncidentEventType::PublicUpdate ? $event->message : null,
                            'at' => $event->created_at->toIso8601String(),
                        ])
                        ->all(),
                ];
            })
            ->all());
    }

    /**
     * @param  Collection<string, string>  $namesByMonitor
     * @return list<array<string, mixed>>
     */
    private function maintenance(StatusPage $statusPage, Collection $namesByMonitor, CarbonImmutable $now): array
    {
        if ($namesByMonitor->isEmpty()) {
            return [];
        }

        return array_values(MaintenanceWindow::query()
            ->where('organization_id', $statusPage->organization_id)
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(self::MAINTENANCE_LOOKAHEAD_DAYS))
            ->whereHas('monitors', fn ($query) => $query->whereIn('monitors.id', $namesByMonitor->keys()->all()))
            ->with('monitors:id')
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (MaintenanceWindow $window): array => [
                'key' => self::publicKey($statusPage, $window->id),
                'title' => $window->title,
                'description' => $window->description,
                'starts_at' => $window->starts_at->toIso8601String(),
                'ends_at' => $window->ends_at->toIso8601String(),
                'active' => $window->isActive($now),
                'components' => $window->monitors
                    ->map(fn (Monitor $monitor): ?string => $namesByMonitor->get($monitor->id))
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->all());
    }

    /**
     * Opaque, stable per-page key: internal identifiers are not published.
     */
    private static function publicKey(StatusPage $statusPage, string $id): string
    {
        return substr(hash_hmac('sha256', $id, $statusPage->id), 0, 16);
    }
}
