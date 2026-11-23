<?php

namespace App\Http\Controllers\Incidents;

use App\Domain\Incidents\Actions\AcknowledgeIncident;
use App\Domain\Incidents\Actions\AddIncidentNote;
use App\Domain\Incidents\Actions\PostPublicIncidentUpdate;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAny', [Incident::class, $organization]);

        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'open', 'resolved'])],
        ])['status'] ?? 'all';

        $incidents = $organization->incidents()
            ->with('monitor:id,name,deleted_at')
            ->when($filter !== 'all', fn ($query) => $query->where('status', $filter))
            ->orderBy('status')
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Incident $incident): array => IncidentPresenter::summary($incident));

        return Inertia::render('incidents/Index', [
            'incidents' => $incidents,
            'filter' => $filter,
        ]);
    }

    public function show(Request $request, Organization $organization, Incident $incident): Response
    {
        Gate::authorize('view', [$incident, $organization]);

        $incident->load(['monitor:id,name,deleted_at', 'acknowledger:id,name', 'events.user:id,name', 'evidence']);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->can('acknowledge', [$incident, $organization]);

        $deliveries = $incident->deliveries()
            ->with('channel:id,name,type')
            ->orderBy('created_at')
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'channel' => $delivery->channel->name,
                'channel_type' => $delivery->channel->type->value,
                'event' => $delivery->event_type->value,
                'status' => $delivery->status->value,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->last_error,
                'sent_at' => $delivery->sent_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('incidents/Show', [
            'incident' => IncidentPresenter::detail($incident),
            'deliveries' => $deliveries,
            'can' => ['acknowledge' => $canManage, 'addNote' => $canManage, 'postPublicUpdate' => $canManage],
            'noteMaxLength' => AddIncidentNote::MAX_LENGTH,
            'publicUpdateMaxLength' => PostPublicIncidentUpdate::MAX_LENGTH,
        ]);
    }

    public function acknowledge(Request $request, Organization $organization, Incident $incident, AcknowledgeIncident $acknowledgeIncident): RedirectResponse
    {
        Gate::authorize('acknowledge', [$incident, $organization]);

        /** @var User $user */
        $user = $request->user();
        $acknowledged = $acknowledgeIncident->handle($incident, $user);

        Inertia::flash('toast', [
            'type' => $acknowledged ? 'success' : 'info',
            'message' => $acknowledged ? __('Incident acknowledged.') : __('This incident was already acknowledged.'),
        ]);

        return back();
    }

    public function addNote(Request $request, Organization $organization, Incident $incident, AddIncidentNote $addIncidentNote): RedirectResponse
    {
        Gate::authorize('addNote', [$incident, $organization]);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:'.AddIncidentNote::MAX_LENGTH],
        ]);

        /** @var User $user */
        $user = $request->user();
        $addIncidentNote->handle($incident, $user, (string) $validated['message']);

        return back();
    }

    public function postPublicUpdate(Request $request, Organization $organization, Incident $incident, PostPublicIncidentUpdate $postPublicIncidentUpdate): RedirectResponse
    {
        Gate::authorize('postPublicUpdate', [$incident, $organization]);

        $validated = $request->validate([
            'public_message' => ['required', 'string', 'min:1', 'max:'.PostPublicIncidentUpdate::MAX_LENGTH],
        ]);

        /** @var User $user */
        $user = $request->user();
        $postPublicIncidentUpdate->handle($incident, $user, (string) $validated['public_message']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Public update published.')]);

        return back();
    }
}
