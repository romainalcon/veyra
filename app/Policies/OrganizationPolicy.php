<?php

namespace App\Policies;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class OrganizationPolicy
{
    use ResolvesOrganizationRole;

    public function view(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization) !== null;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canUpdateOrganization() ?? false;
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canDeleteOrganization() ?? false;
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canManageMembers() ?? false;
    }

    public function invite(User $user, Organization $organization, OrganizationRole $role): bool
    {
        return $this->roleOf($user, $organization)?->canAssign($role) ?? false;
    }

    public function updateMember(User $user, Organization $organization, OrganizationMember $member, OrganizationRole $newRole): bool
    {
        if ($member->organization_id !== $organization->id) {
            return false;
        }

        $actor = $this->roleOf($user, $organization);

        return $actor !== null && $actor->canAssign($member->role) && $actor->canAssign($newRole);
    }

    public function removeMember(User $user, Organization $organization, OrganizationMember $member): bool
    {
        if ($member->organization_id !== $organization->id) {
            return false;
        }

        if ($member->user_id === $user->id) {
            return true;
        }

        return $this->roleOf($user, $organization)?->canAssign($member->role) ?? false;
    }

    public function revokeInvitation(User $user, Organization $organization, OrganizationInvitation $invitation): bool
    {
        if ($invitation->organization_id !== $organization->id) {
            return false;
        }

        return $this->roleOf($user, $organization)?->canAssign($invitation->role) ?? false;
    }
}
