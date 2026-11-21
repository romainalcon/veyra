<?php

namespace App\Policies\Concerns;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;

trait ResolvesOrganizationRole
{
    /**
     * Reuse the membership resolved by the ResolveOrganization middleware when
     * available, to avoid one query per authorization check.
     */
    protected function roleOf(User $user, Organization $organization): ?OrganizationRole
    {
        if ($organization->relationLoaded('viewerMembership')) {
            $membership = $organization->getRelation('viewerMembership');

            if ($membership instanceof OrganizationMember && $membership->user_id === $user->id) {
                return $membership->role;
            }
        }

        return $user->roleIn($organization);
    }
}
