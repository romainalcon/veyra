<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Localization\SupportedLocales;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Stats\IncidentSeries;
use App\Domain\Monitoring\Stats\StatsRange;
use App\Domain\Monitoring\Stats\UptimeCalculator;
use App\Domain\Organizations\Actions\CreateOrganization;
use App\Domain\Organizations\Actions\DeleteOrganization;
use App\Domain\Organizations\Actions\UpdateOrganization;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Requests\Organizations\SaveOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('organizations/Create');
    }

    public function store(SaveOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organization = $createOrganization->handle($user, $request->toData());

        return to_route('organizations.dashboard', $organization);
    }

    public function dashboard(Organization $organization, UptimeCalculator $uptimeCalculator, IncidentSeries $incidentSeries): Response
    {
        Gate::authorize('view', $organization);

        $statusCounts = $organization->monitors()
            ->where('monitors.enabled', true)
            ->leftJoin('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
            ->selectRaw("COALESCE(monitor_states.status, 'unknown') as status, COUNT(*) as aggregate")
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $openIncidents = $organization->incidents()
            ->where('status', IncidentStatus::Open)
            ->with('monitor:id,name,deleted_at')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get()
            ->map(fn (Incident $incident): array => IncidentPresenter::summary($incident))
            ->all();

        return Inertia::render('organizations/Dashboard', [
            'monitorCounts' => [
                'total' => $organization->monitors()->count(),
                'paused' => $organization->monitors()->where('enabled', false)->count(),
                'byStatus' => collect(MonitorStatus::cases())
                    ->mapWithKeys(fn (MonitorStatus $status): array => [$status->value => (int) ($statusCounts[$status->value] ?? 0)])
                    ->all(),
            ],
            'openIncidents' => $openIncidents,
            'analytics' => Inertia::defer(function () use ($organization, $uptimeCalculator, $incidentSeries): array {
                $monitors = $organization->monitors()->with('state')->orderBy('name')->get();
                $weekly = $uptimeCalculator->forMonitors($monitors, StatsRange::Week);
                $daily = $uptimeCalculator->forMonitors($monitors, StatsRange::Day);
                $latency = DB::table('monitor_checks')
                    ->whereIn('monitor_id', $monitors->pluck('id'))
                    ->where('checked_at', '>=', now()->subDay())
                    ->where('success', true)
                    ->groupBy('monitor_id')
                    ->selectRaw('monitor_id, ROUND(AVG(duration_ms)) as latency')
                    ->pluck('latency', 'monitor_id');

                return [
                    'uptime' => [
                        '24h' => UptimeCalculator::combine($daily)->toArray(),
                        '7d' => UptimeCalculator::combine($weekly)->toArray(),
                        '30d' => UptimeCalculator::combine($uptimeCalculator->forMonitors($monitors, StatsRange::Month))->toArray(),
                    ],
                    'monitors' => $monitors
                        ->map(fn (Monitor $monitor): array => [
                            'id' => $monitor->id,
                            'name' => $monitor->name,
                            'status' => ($monitor->state->status ?? MonitorStatus::Unknown)->value,
                            'uptime_7d' => $weekly[$monitor->id]->uptime(),
                            'partial' => $weekly[$monitor->id]->isPartial(),
                            'latency_24h' => isset($latency[$monitor->id]) ? (int) $latency[$monitor->id] : null,
                        ])
                        ->sortBy(fn (array $row): float => $row['uptime_7d'] ?? 101.0)
                        ->values()
                        ->all(),
                    'incidents' => $incidentSeries->forOrganization($organization, 30),
                ];
            }),
        ]);
    }

    public function edit(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Settings', [
            'organization' => $organization->only(['id', 'name', 'slug', 'locale']),
            'locales' => SupportedLocales::options(),
        ]);
    }

    public function update(SaveOrganizationRequest $request, Organization $organization, UpdateOrganization $updateOrganization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $updateOrganization->handle($organization, $request->toData());

        // The confirmation already speaks the language that was just chosen.
        app()->setLocale(SupportedLocales::resolve($organization->locale));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('organizations.edit', $organization);
    }

    public function destroy(Request $request, Organization $organization, DeleteOrganization $deleteOrganization): RedirectResponse
    {
        Gate::authorize('delete', $organization);

        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$organization->slug],
        ]);

        $deleteOrganization->handle($organization);

        return to_route('dashboard');
    }
}
