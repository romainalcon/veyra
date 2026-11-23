<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Actions\CreateMonitor;
use App\Domain\Monitoring\Actions\DeleteMonitor;
use App\Domain\Monitoring\Actions\UpdateMonitor;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\Tag;
use App\Domain\Monitoring\Stats\MonitorSeries;
use App\Domain\Monitoring\Stats\StatsRange;
use App\Domain\Monitoring\Stats\UptimeCalculator;
use App\Domain\Monitoring\Support\HeartbeatToken;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Presenters\MonitorPresenter;
use App\Http\Requests\Monitors\SaveMonitorRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MonitorController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAny', [Monitor::class, $organization]);

        $tag = self::tagFilter($request);

        $monitors = $organization->monitors()
            ->with(['httpConfig', 'tcpConfig', 'heartbeatConfig', 'dnsConfig', 'latestCheck', 'state', 'tags'])
            ->when($tag !== null, fn ($query) => $query->taggedWith((string) $tag))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Monitor $monitor): array => MonitorPresenter::summary($monitor));

        return Inertia::render('monitors/Index', [
            'monitors' => $monitors,
            'tag' => $tag,
            'tagOptions' => MonitorPresenter::tagOptions($organization),
            'canCreate' => $request->user()?->can('create', [Monitor::class, $organization]) ?? false,
        ]);
    }

    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        return Inertia::render('monitors/Create', MonitorPresenter::formOptions($organization));
    }

    public function store(SaveMonitorRequest $request, Organization $organization, CreateMonitor $createMonitor): RedirectResponse
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        $data = $request->toData();
        $pingToken = $data->type === MonitorType::Heartbeat ? HeartbeatToken::generate() : null;

        $monitor = $createMonitor->handle($organization, $data, $pingToken);

        if ($pingToken !== null) {
            Inertia::flash('pingUrl', route('heartbeats.ping', $pingToken));
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Monitor created. Copy the ping URL now: it is shown only once.')]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Monitor created. The first check is on its way.')]);
        }

        return to_route('organizations.monitors.show', [$organization, $monitor]);
    }

    public function show(Request $request, Organization $organization, Monitor $monitor, UptimeCalculator $uptimeCalculator, MonitorSeries $series): Response
    {
        Gate::authorize('view', [$monitor, $organization]);

        $monitor->load(['httpConfig', 'tcpConfig', 'heartbeatConfig', 'dnsConfig', 'domain', 'tlsCertificate', 'latestCheck', 'state']);

        $checks = $monitor->checks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->through(fn (MonitorCheck $check): array => MonitorPresenter::check($check));

        $canManage = $request->user()?->can('update', [$monitor, $organization]) ?? false;

        $incidents = $monitor->incidents()
            ->orderByDesc('started_at')
            ->limit(10)
            ->get()
            ->each(fn (Incident $incident) => $incident->setRelation('monitor', $monitor))
            ->map(fn (Incident $incident): array => IncidentPresenter::summary($incident))
            ->all();

        $range = StatsRange::tryFrom((string) $request->query('range')) ?? StatsRange::Day;

        return Inertia::render('monitors/Show', [
            'monitor' => MonitorPresenter::detail($monitor),
            'incidents' => $incidents,
            'maintenance' => $this->currentOrNextMaintenance($monitor),
            'range' => $range->value,
            'stats' => Inertia::defer(function () use ($monitor, $range, $uptimeCalculator, $series): array {
                $monitors = collect([$monitor]);

                return [
                    'uptime' => collect([StatsRange::Day, StatsRange::Week, StatsRange::Month])
                        ->mapWithKeys(fn (StatsRange $period): array => [
                            $period->value => $uptimeCalculator->forMonitors($monitors, $period)[$monitor->id]->toArray(),
                        ])
                        ->all(),
                    'days' => $series->uptimeDays($monitor, 90),
                    'latency' => $series->latency($monitor, $range),
                ];
            }),
            'checks' => $checks,
            'can' => [
                'update' => $canManage,
                'delete' => $canManage,
                'checkNow' => $request->user()?->can('checkNow', [$monitor, $organization]) ?? false,
            ],
        ]);
    }

    public function edit(Organization $organization, Monitor $monitor): Response
    {
        Gate::authorize('update', [$monitor, $organization]);

        $monitor->load(['httpConfig', 'tcpConfig', 'heartbeatConfig', 'dnsConfig', 'domain', 'tlsCertificate']);

        return Inertia::render('monitors/Edit', [
            ...MonitorPresenter::formOptions($organization, $monitor->interval_seconds),
            'monitor' => MonitorPresenter::detail($monitor),
        ]);
    }

    public function update(SaveMonitorRequest $request, Organization $organization, Monitor $monitor, UpdateMonitor $updateMonitor): RedirectResponse
    {
        Gate::authorize('update', [$monitor, $organization]);

        $updateMonitor->handle($monitor, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Monitor updated.')]);

        return to_route('organizations.monitors.show', [$organization, $monitor]);
    }

    public function destroy(Organization $organization, Monitor $monitor, DeleteMonitor $deleteMonitor): RedirectResponse
    {
        Gate::authorize('delete', [$monitor, $organization]);

        $deleteMonitor->handle($monitor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Monitor deleted.')]);

        return to_route('organizations.monitors.index', $organization);
    }

    /**
     * The normalized `?tag=` filter of lists and the dashboard (ADR-047).
     */
    public static function tagFilter(Request $request): ?string
    {
        $tag = $request->query('tag');

        return is_string($tag) && trim($tag) !== '' ? Tag::normalize($tag) : null;
    }

    /**
     * The running window, or else the next scheduled one (within 7 days).
     *
     * @return array{title: string, starts_at: string, ends_at: string, active: bool}|null
     */
    private function currentOrNextMaintenance(Monitor $monitor): ?array
    {
        $now = CarbonImmutable::now();

        $window = MaintenanceWindow::query()
            ->whereHas('monitors', fn ($query) => $query->whereKey($monitor->id))
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(7))
            ->orderBy('starts_at')
            ->first();

        return $window === null ? null : [
            'title' => $window->title,
            'starts_at' => $window->starts_at->toIso8601String(),
            'ends_at' => $window->ends_at->toIso8601String(),
            'active' => $window->isActive($now),
        ];
    }
}
