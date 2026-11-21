<?php

namespace App\Policies;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class IncidentPolicy
{
    use ResolvesOrganizationRole;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization) !== null;
    }

    public function view(User $user, Incident $incident, Organization $organization): bool
    {
        return $incident->organization_id === $organization->id && $this->viewAny($user, $organization);
    }

    public function acknowledge(User $user, Incident $incident, Organization $organization): bool
    {
        return $incident->organization_id === $organization->id
            && ($this->roleOf($user, $organization)?->canManageResources() ?? false);
    }

    public function addNote(User $user, Incident $incident, Organization $organization): bool
    {
        return $this->acknowledge($user, $incident, $organization);
    }

    public function postPublicUpdate(User $user, Incident $incident, Organization $organization): bool
    {
        return $this->acknowledge($user, $incident, $organization);
    }
}
