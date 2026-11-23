<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Monitoring\Actions\CreateMonitor;
use App\Domain\Monitoring\Actions\DeleteMonitor;
use App\Domain\Monitoring\Actions\SetMonitorEnabled;
use App\Domain\Monitoring\Actions\UpdateMonitor;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\HeartbeatToken;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Monitors\MonitorController as WebMonitorController;
use App\Http\Requests\Api\V1\SaveMonitorRequest;
use App\Http\Resources\Api\V1\MonitorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MonitorController extends ApiController
{
    private const RELATIONS = ['httpConfig', 'tcpConfig', 'heartbeatConfig', 'dnsConfig', 'tlsCertificate', 'state', 'notificationChannels:id', 'tags'];

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Monitor::class, $organization]);

        $tag = WebMonitorController::tagFilter($request);

        $monitors = $organization->monitors()
            ->with(self::RELATIONS)
            ->when($tag !== null, fn ($query) => $query->taggedWith((string) $tag))
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return MonitorResource::collection($monitors);
    }

    public function show(Organization $organization, Monitor $monitor): MonitorResource
    {
        Gate::authorize('view', [$monitor, $organization]);

        return new MonitorResource($monitor->load(self::RELATIONS));
    }

    /**
     * A heartbeat's ping URL is returned once, in this response only.
     */
    public function store(SaveMonitorRequest $request, Organization $organization, CreateMonitor $createMonitor): JsonResponse
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        $data = $request->toData();
        $pingToken = $data->type === MonitorType::Heartbeat ? HeartbeatToken::generate() : null;

        $monitor = $createMonitor->handle($organization, $data, $pingToken);

        return (new MonitorResource($monitor->load(self::RELATIONS)))
            ->additional($pingToken === null ? [] : ['meta' => ['ping_url' => route('heartbeats.ping', $pingToken)]])
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveMonitorRequest $request, Organization $organization, Monitor $monitor, UpdateMonitor $updateMonitor): MonitorResource
    {
        Gate::authorize('update', [$monitor, $organization]);

        $updateMonitor->handle($monitor, $request->toData());

        return new MonitorResource($monitor->refresh()->load(self::RELATIONS));
    }

    public function destroy(Organization $organization, Monitor $monitor, DeleteMonitor $deleteMonitor): Response
    {
        Gate::authorize('delete', [$monitor, $organization]);

        $deleteMonitor->handle($monitor);

        return response()->noContent();
    }

    public function pause(Organization $organization, Monitor $monitor, SetMonitorEnabled $setMonitorEnabled): MonitorResource
    {
        Gate::authorize('update', [$monitor, $organization]);

        $setMonitorEnabled->handle($monitor, false);

        return new MonitorResource($monitor->refresh()->load(self::RELATIONS));
    }

    /**
     * Resuming counts against the active monitor limit (422 when reached).
     */
    public function resume(Organization $organization, Monitor $monitor, SetMonitorEnabled $setMonitorEnabled): MonitorResource
    {
        Gate::authorize('update', [$monitor, $organization]);

        $setMonitorEnabled->handle($monitor, true);

        return new MonitorResource($monitor->refresh()->load(self::RELATIONS));
    }
}
