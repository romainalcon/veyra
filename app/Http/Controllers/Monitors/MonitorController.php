<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Monitoring\Actions\CreateMonitor;
use App\Domain\Monitoring\Actions\DeleteMonitor;
use App\Domain\Monitoring\Actions\UpdateMonitor;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MonitorPresenter;
use App\Http\Requests\Monitors\SaveMonitorRequest;
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

        $monitors = $organization->monitors()
            ->with(['httpConfig', 'latestCheck'])
            ->orderBy('name')
            ->paginate(25)
            ->through(fn (Monitor $monitor): array => MonitorPresenter::summary($monitor));

        return Inertia::render('monitors/Index', [
            'monitors' => $monitors,
            'canCreate' => $request->user()?->can('create', [Monitor::class, $organization]) ?? false,
        ]);
    }

    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        return Inertia::render('monitors/Create', MonitorPresenter::formOptions());
    }

    public function store(SaveMonitorRequest $request, Organization $organization, CreateMonitor $createMonitor): RedirectResponse
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        $monitor = $createMonitor->handle($organization, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Monitor created. The first check is on its way.')]);

        return to_route('organizations.monitors.show', [$organization, $monitor]);
    }

    public function show(Request $request, Organization $organization, Monitor $monitor): Response
    {
        Gate::authorize('view', [$monitor, $organization]);

        $monitor->load(['httpConfig', 'latestCheck']);

        $checks = $monitor->checks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->through(fn (MonitorCheck $check): array => MonitorPresenter::check($check));

        $canManage = $request->user()?->can('update', [$monitor, $organization]) ?? false;

        return Inertia::render('monitors/Show', [
            'monitor' => MonitorPresenter::detail($monitor),
            'checks' => $checks,
            'can' => ['update' => $canManage, 'delete' => $canManage, 'checkNow' => $canManage],
        ]);
    }

    public function edit(Organization $organization, Monitor $monitor): Response
    {
        Gate::authorize('update', [$monitor, $organization]);

        $monitor->load('httpConfig');

        return Inertia::render('monitors/Edit', [
            ...MonitorPresenter::formOptions(),
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
}
