<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Http\Resources\Api\V1\CheckResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MonitorCheckController extends ApiController
{
    /**
     * Newest first, on the `(monitor_id, checked_at)` index. Checks older than
     * the organization's retention are already purged.
     */
    public function index(Request $request, Organization $organization, Monitor $monitor): AnonymousResourceCollection
    {
        Gate::authorize('view', [$monitor, $organization]);

        $checks = $monitor->checks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return CheckResource::collection($checks);
    }
}
