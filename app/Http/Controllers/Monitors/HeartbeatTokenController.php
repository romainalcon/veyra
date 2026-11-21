<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Heartbeats\RegenerateHeartbeatToken;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Issues a new ping URL; it is flashed once and never stored in plain text.
 */
class HeartbeatTokenController extends Controller
{
    public function store(Organization $organization, Monitor $monitor, RegenerateHeartbeatToken $regenerateHeartbeatToken): RedirectResponse
    {
        Gate::authorize('update', [$monitor, $organization]);
        abort_unless($monitor->type === MonitorType::Heartbeat, 404);

        $token = $regenerateHeartbeatToken->handle($monitor);

        Inertia::flash('pingUrl', route('heartbeats.ping', $token));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New ping URL issued. The previous one no longer works.')]);

        return to_route('organizations.monitors.show', [$organization, $monitor]);
    }
}
