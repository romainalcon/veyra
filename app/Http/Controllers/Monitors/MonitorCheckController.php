<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Monitoring\Actions\RequestManualCheck;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Check now": queues an immediate, rate-limited check.
 */
class MonitorCheckController extends Controller
{
    public function store(Organization $organization, Monitor $monitor, RequestManualCheck $requestManualCheck): RedirectResponse
    {
        Gate::authorize('checkNow', [$monitor, $organization]);

        if (! $monitor->enabled) {
            throw ValidationException::withMessages(['monitor' => __('Resume the monitor before running a check.')]);
        }

        $queued = $requestManualCheck->handle($monitor);

        Inertia::flash('toast', [
            'type' => $queued ? 'success' : 'info',
            'message' => $queued ? __('Check queued.') : __('A check is already queued.'),
        ]);

        return back();
    }
}
