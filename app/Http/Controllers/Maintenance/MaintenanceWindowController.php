<?php

namespace App\Http\Controllers\Maintenance;

use App\Domain\Maintenance\Actions\CancelMaintenanceWindow;
use App\Domain\Maintenance\Actions\EndMaintenanceWindowNow;
use App\Domain\Maintenance\Actions\SaveMaintenanceWindow;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MaintenanceSeriesPresenter;
use App\Http\Presenters\MaintenanceWindowPresenter;
use App\Http\Requests\Maintenance\SaveMaintenanceWindowRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceWindowController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAny', [MaintenanceWindow::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $now = CarbonImmutable::now();

        $upcoming = $organization->maintenanceWindows()
            ->with(['monitors:id,name', 'creator:id,name'])
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (MaintenanceWindow $window): array => MaintenanceWindowPresenter::summary($window, $now))
            ->all();

        $past = $organization->maintenanceWindows()
            ->with(['monitors:id,name', 'creator:id,name'])
            ->where(fn ($query) => $query->whereNotNull('cancelled_at')->orWhere('ends_at', '<=', $now))
            ->orderByDesc('starts_at')
            ->paginate(20)
            ->through(fn (MaintenanceWindow $window): array => MaintenanceWindowPresenter::summary($window, $now));

        $series = $organization->maintenanceSeries()
            ->with(['monitors:id,name', 'creator:id,name'])
            ->orderBy('title')
            ->get()
            ->map(fn (MaintenanceSeries $series): array => MaintenanceSeriesPresenter::summary($series))
            ->all();

        return Inertia::render('maintenance/Index', [
            'upcoming' => $upcoming,
            'series' => $series,
            'timezone' => $organization->timezone,
            'past' => $past,
            'canManage' => $user->can('create', [MaintenanceWindow::class, $organization]),
        ]);
    }

    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [MaintenanceWindow::class, $organization]);

        return Inertia::render('maintenance/Create', [
            'monitors' => $this->monitorOptions($organization),
            'maxDurationHours' => (int) config('veyra.maintenance.max_duration_hours'),
        ]);
    }

    public function store(SaveMaintenanceWindowRequest $request, Organization $organization, SaveMaintenanceWindow $saveMaintenanceWindow): RedirectResponse
    {
        Gate::authorize('create', [MaintenanceWindow::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $saveMaintenanceWindow->handle($organization, $request->payload(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window scheduled.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    public function edit(Organization $organization, MaintenanceWindow $maintenanceWindow): Response|RedirectResponse
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        // Occurrences are edited through their series (ADR-041).
        if ($maintenanceWindow->series_id !== null) {
            return to_route('organizations.maintenance.series.edit', [$organization, $maintenanceWindow->series_id]);
        }

        $maintenanceWindow->load(['monitors:id,name', 'creator:id,name']);

        return Inertia::render('maintenance/Edit', [
            'window' => MaintenanceWindowPresenter::summary($maintenanceWindow),
            'monitors' => $this->monitorOptions($organization),
            'maxDurationHours' => (int) config('veyra.maintenance.max_duration_hours'),
        ]);
    }

    public function update(SaveMaintenanceWindowRequest $request, Organization $organization, MaintenanceWindow $maintenanceWindow, SaveMaintenanceWindow $saveMaintenanceWindow): RedirectResponse
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $saveMaintenanceWindow->handle($organization, $request->payload(), window: $maintenanceWindow);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window updated.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    public function cancel(Organization $organization, MaintenanceWindow $maintenanceWindow, CancelMaintenanceWindow $cancelMaintenanceWindow): RedirectResponse
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $cancelMaintenanceWindow->handle($maintenanceWindow);

        Inertia::flash('toast', ['type' => 'success', 'message' => $maintenanceWindow->series_id !== null
            ? __('Occurrence skipped.')
            : __('Maintenance window cancelled.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    public function end(Organization $organization, MaintenanceWindow $maintenanceWindow, EndMaintenanceWindowNow $endMaintenanceWindowNow): RedirectResponse
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $endMaintenanceWindowNow->handle($maintenanceWindow);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window ended.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function monitorOptions(Organization $organization): array
    {
        return array_values($organization->monitors()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($monitor): array => ['id' => (string) $monitor->id, 'name' => (string) $monitor->name])
            ->all());
    }
}
