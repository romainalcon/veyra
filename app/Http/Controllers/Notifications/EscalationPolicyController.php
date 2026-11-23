<?php

namespace App\Http\Controllers\Notifications;

use App\Domain\Notifications\Actions\DeleteEscalationPolicy;
use App\Domain\Notifications\Actions\SaveEscalationPolicy;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\SaveEscalationPolicyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Escalation policies are listed on the notifications page (ADR-045).
 */
class EscalationPolicyController extends Controller
{
    public function store(SaveEscalationPolicyRequest $request, Organization $organization, SaveEscalationPolicy $saveEscalationPolicy): RedirectResponse
    {
        Gate::authorize('create', [EscalationPolicy::class, $organization]);

        $saveEscalationPolicy->handle($organization, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Escalation policy created.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }

    public function update(SaveEscalationPolicyRequest $request, Organization $organization, EscalationPolicy $escalationPolicy, SaveEscalationPolicy $saveEscalationPolicy): RedirectResponse
    {
        Gate::authorize('update', [$escalationPolicy, $organization]);

        $saveEscalationPolicy->handle($organization, $request->payload(), $escalationPolicy);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Escalation policy updated.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }

    /**
     * Monitors using the policy fall back to the organization default
     * (`monitors.escalation_policy_id` is nulled by the foreign key).
     */
    public function destroy(Organization $organization, EscalationPolicy $escalationPolicy, DeleteEscalationPolicy $deleteEscalationPolicy): RedirectResponse
    {
        Gate::authorize('delete', [$escalationPolicy, $organization]);

        $deleteEscalationPolicy->handle($escalationPolicy);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Escalation policy deleted.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }
}
