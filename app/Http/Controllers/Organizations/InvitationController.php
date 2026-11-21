<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Actions\InviteMember;
use App\Domain\Organizations\Actions\RevokeInvitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class InvitationController extends Controller
{
    public function store(StoreInvitationRequest $request, Organization $organization, InviteMember $inviteMember): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inviteMember->handle($organization, $user, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('organizations.members.index', $organization);
    }

    public function destroy(Organization $organization, OrganizationInvitation $invitation, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        Gate::authorize('revokeInvitation', [$organization, $invitation]);

        $revokeInvitation->handle($invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return to_route('organizations.members.index', $organization);
    }
}
