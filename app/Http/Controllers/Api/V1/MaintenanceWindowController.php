<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Actions\CancelMaintenanceWindow;
use App\Domain\Maintenance\Actions\EndMaintenanceWindowNow;
use App\Domain\Maintenance\Actions\SaveMaintenanceWindow;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Models\Organization;
use App\Http\Requests\Maintenance\SaveMaintenanceWindowRequest;
use App\Http\Resources\Api\V1\MaintenanceWindowResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MaintenanceWindowController extends ApiController
{
    /**
     * Newest start first; `upcoming=1` keeps only windows that have not ended
     * and were not cancelled.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaintenanceWindow::class, $organization]);

        $windows = $organization->maintenanceWindows()
            ->with('monitors:id')
            ->when($request->boolean('upcoming'), fn ($query) => $query
                ->whereNull('cancelled_at')
                ->where('ends_at', '>', CarbonImmutable::now()))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return MaintenanceWindowResource::collection($windows);
    }

    public function show(Organization $organization, MaintenanceWindow $maintenanceWindow): MaintenanceWindowResource
    {
        Gate::authorize('view', [$maintenanceWindow, $organization]);

        return new MaintenanceWindowResource($maintenanceWindow->load('monitors:id'));
    }

    public function store(SaveMaintenanceWindowRequest $request, Organization $organization, SaveMaintenanceWindow $saveMaintenanceWindow): JsonResponse
    {
        Gate::authorize('create', [MaintenanceWindow::class, $organization]);

        $window = $saveMaintenanceWindow->handle($organization, $request->payload());

        return (new MaintenanceWindowResource($window->load('monitors:id')))->response()->setStatusCode(201);
    }

    /**
     * Occurrences of a recurring series are edited through the series in the
     * interface (ADR-041, 422 here); they can still be cancelled (skipped) or ended.
     */
    public function update(SaveMaintenanceWindowRequest $request, Organization $organization, MaintenanceWindow $maintenanceWindow, SaveMaintenanceWindow $saveMaintenanceWindow): MaintenanceWindowResource
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $window = $saveMaintenanceWindow->handle($organization, $request->payload(), window: $maintenanceWindow);

        return new MaintenanceWindowResource($window->load('monitors:id'));
    }

    public function cancel(Organization $organization, MaintenanceWindow $maintenanceWindow, CancelMaintenanceWindow $cancelMaintenanceWindow): MaintenanceWindowResource
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $cancelMaintenanceWindow->handle($maintenanceWindow);

        return new MaintenanceWindowResource($maintenanceWindow->refresh()->load('monitors:id'));
    }

    public function end(Organization $organization, MaintenanceWindow $maintenanceWindow, EndMaintenanceWindowNow $endMaintenanceWindowNow): MaintenanceWindowResource
    {
        Gate::authorize('update', [$maintenanceWindow, $organization]);

        $endMaintenanceWindowNow->handle($maintenanceWindow);

        return new MaintenanceWindowResource($maintenanceWindow->refresh()->load('monitors:id'));
    }
}
