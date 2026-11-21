<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Monitoring\Actions\SetMonitorEnabled;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Pause / resume a monitor.
 */
class MonitorStatusController extends Controller
{
    public function __invoke(Request $request, Organization $organization, Monitor $monitor, SetMonitorEnabled $setMonitorEnabled): RedirectResponse
    {
        Gate::authorize('update', [$monitor, $organization]);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $setMonitorEnabled->handle($monitor, (bool) $validated['enabled']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $monitor->enabled ? __('Monitor resumed.') : __('Monitor paused.'),
        ]);

        return back();
    }
}
