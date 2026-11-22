<?php

namespace App\Policies\Concerns;

use App\Domain\Organizations\Contracts\OrganizationActor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;

trait ResolvesOrganizationRole
{
    /**
     * Reuse the membership resolved by the ResolveOrganization middleware when
     * available, to avoid one query per authorization check. API tokens
     * carry their role themselves (ADR-044).
     */
    protected function roleOf(OrganizationActor $actor, Organization $organization): ?OrganizationRole
    {
        if ($actor instanceof User && $organization->relationLoaded('viewerMembership')) {
            $membership = $organization->getRelation('viewerMembership');

            if ($membership instanceof OrganizationMember && $membership->user_id === $actor->id) {
                return $membership->role;
            }
        }

        return $actor->roleIn($organization);
    }
}
