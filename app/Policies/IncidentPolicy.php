<?php

namespace App\Policies;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Organizations\Contracts\OrganizationActor;
use App\Domain\Organizations\Models\Organization;
use App\Policies\Concerns\ResolvesOrganizationRole;

class IncidentPolicy
{
    use ResolvesOrganizationRole;

    public function viewAny(OrganizationActor $actor, Organization $organization): bool
    {
        return $this->roleOf($actor, $organization) !== null;
    }

    public function view(OrganizationActor $actor, Incident $incident, Organization $organization): bool
    {
        return $incident->organization_id === $organization->id && $this->viewAny($actor, $organization);
    }

    public function acknowledge(OrganizationActor $actor, Incident $incident, Organization $organization): bool
    {
        return $incident->organization_id === $organization->id
            && ($this->roleOf($actor, $organization)?->canManageResources() ?? false);
    }

    public function addNote(OrganizationActor $actor, Incident $incident, Organization $organization): bool
    {
        return $this->acknowledge($actor, $incident, $organization);
    }

    public function postPublicUpdate(OrganizationActor $actor, Incident $incident, Organization $organization): bool
    {
        return $this->acknowledge($actor, $incident, $organization);
    }
}
