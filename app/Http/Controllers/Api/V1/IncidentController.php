<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Incidents\Actions\AcknowledgeIncident;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Http\Resources\Api\V1\IncidentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IncidentController extends ApiController
{
    /**
     * Newest first; `status` and `monitor_id` narrow the list.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Incident::class, $organization]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(IncidentStatus::class)],
            'monitor_id' => ['nullable', 'uuid'],
        ]);

        $incidents = $organization->incidents()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['monitor_id'] ?? null, fn ($query, string $monitorId) => $query->where('monitor_id', $monitorId))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return IncidentResource::collection($incidents);
    }

    public function show(Organization $organization, Incident $incident): IncidentResource
    {
        Gate::authorize('view', [$incident, $organization]);

        return new IncidentResource($incident->load('events'));
    }

    /**
     * Idempotent: acknowledging an acknowledged incident changes nothing.
     */
    public function acknowledge(Request $request, Organization $organization, Incident $incident, AcknowledgeIncident $acknowledgeIncident): IncidentResource
    {
        Gate::authorize('acknowledge', [$incident, $organization]);

        /** @var ApiToken $token */
        $token = $request->user();
        $acknowledgeIncident->handle($incident, $token);

        return new IncidentResource($incident->refresh()->load('events'));
    }
}
