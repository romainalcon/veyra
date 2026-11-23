<?php

namespace App\Policies;

use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

/**
 * Escalation policies route alerts like channels do: only admins and owners
 * manage them (ADR-045). Every member may see them.
 */
class EscalationPolicyPolicy
{
    use ResolvesOrganizationRole;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canUpdateOrganization() ?? false;
    }

    public function update(User $user, EscalationPolicy $policy, Organization $organization): bool
    {
        return $policy->organization_id === $organization->id && $this->create($user, $organization);
    }

    public function delete(User $user, EscalationPolicy $policy, Organization $organization): bool
    {
        return $this->update($user, $policy, $organization);
    }
}
