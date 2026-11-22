<?php

namespace App\Http\Controllers\Maintenance;

use App\Domain\Maintenance\Actions\DeleteMaintenanceSeries;
use App\Domain\Maintenance\Actions\SaveMaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MaintenanceSeriesPresenter;
use App\Http\Requests\Maintenance\SaveMaintenanceSeriesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceSeriesController extends Controller
{
    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [MaintenanceSeries::class, $organization]);

        return Inertia::render('maintenance/series/Create', $this->formProps($organization));
    }

    public function store(SaveMaintenanceSeriesRequest $request, Organization $organization, SaveMaintenanceSeries $saveMaintenanceSeries): RedirectResponse
    {
        Gate::authorize('create', [MaintenanceSeries::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $saveMaintenanceSeries->handle($organization, $request->payload(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recurring maintenance scheduled.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    public function edit(Organization $organization, MaintenanceSeries $maintenanceSeries): Response
    {
        Gate::authorize('update', [$maintenanceSeries, $organization]);

        $maintenanceSeries->load(['monitors:id,name', 'creator:id,name']);

        return Inertia::render('maintenance/series/Edit', [
            ...$this->formProps($organization),
            'series' => MaintenanceSeriesPresenter::summary($maintenanceSeries),
        ]);
    }

    public function update(SaveMaintenanceSeriesRequest $request, Organization $organization, MaintenanceSeries $maintenanceSeries, SaveMaintenanceSeries $saveMaintenanceSeries): RedirectResponse
    {
        Gate::authorize('update', [$maintenanceSeries, $organization]);

        $saveMaintenanceSeries->handle($organization, $request->payload(), series: $maintenanceSeries);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recurring maintenance updated.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    public function destroy(Organization $organization, MaintenanceSeries $maintenanceSeries, DeleteMaintenanceSeries $deleteMaintenanceSeries): RedirectResponse
    {
        Gate::authorize('delete', [$maintenanceSeries, $organization]);

        $deleteMaintenanceSeries->handle($maintenanceSeries);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recurring maintenance deleted.')]);

        return to_route('organizations.maintenance.index', $organization);
    }

    /**
     * @return array{monitors: list<array{id: string, name: string}>, maxDurationHours: int, timezone: string}
     */
    private function formProps(Organization $organization): array
    {
        return [
            'monitors' => array_values($organization->monitors()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($monitor): array => ['id' => (string) $monitor->id, 'name' => (string) $monitor->name])
                ->all()),
            'maxDurationHours' => (int) config('veyra.maintenance.max_duration_hours'),
            'timezone' => $organization->timezone,
        ];
    }
}
