<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Actions\ChangeMemberRole;
use App\Domain\Organizations\Actions\RemoveMember;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateMemberRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewMembers', $organization);

        /** @var User $user */
        $user = $request->user();

        /** @var OrganizationMember $viewer */
        $viewer = $organization->getRelation('viewerMembership');
        $canManage = $user->can('manageMembers', $organization);

        $members = $organization->members()
            ->with('user:id,name,email')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (OrganizationMember $member): array => [
                'id' => $member->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'role' => $member->role->value,
                'joined_at' => $member->joined_at->toIso8601String(),
                'is_current_user' => $member->user_id === $user->id,
                'can_update' => $canManage && $viewer->role->canAssign($member->role),
                'can_remove' => $user->can('removeMember', [$organization, $member]),
            ]);

        $invitations = $canManage
            ? $organization->invitations()
                ->pending()
                ->orderBy('created_at')
                ->get()
                ->map(fn (OrganizationInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'can_revoke' => $viewer->role->canAssign($invitation->role),
                ])
            : [];

        return Inertia::render('organizations/Members', [
            'members' => $members,
            'invitations' => $invitations,
            'assignableRoles' => array_map(
                fn (OrganizationRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                $canManage ? $viewer->role->assignableRoles() : [],
            ),
        ]);
    }

    public function update(UpdateMemberRequest $request, Organization $organization, OrganizationMember $member, ChangeMemberRole $changeMemberRole): RedirectResponse
    {
        $changeMemberRole->handle($member, $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('organizations.members.index', $organization);
    }

    public function destroy(Request $request, Organization $organization, OrganizationMember $member, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('removeMember', [$organization, $member]);

        $removeMember->handle($member);

        if ($member->user_id === $request->user()?->id) {
            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('organizations.members.index', $organization);
    }
}
