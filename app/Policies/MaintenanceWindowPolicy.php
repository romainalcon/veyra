<?php

namespace App\Policies;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Contracts\OrganizationActor;
use App\Domain\Organizations\Models\Organization;
use App\Policies\Concerns\ResolvesOrganizationRole;

class MaintenanceWindowPolicy
{
    use ResolvesOrganizationRole;

    public function viewAny(OrganizationActor $actor, Organization $organization): bool
    {
        return $this->roleOf($actor, $organization) !== null;
    }

    public function create(OrganizationActor $actor, Organization $organization): bool
    {
        return $this->roleOf($actor, $organization)?->canManageResources() ?? false;
    }

    public function view(OrganizationActor $actor, MaintenanceWindow $window, Organization $organization): bool
    {
        return $this->belongsTo($window, $organization) && $this->viewAny($actor, $organization);
    }

    public function update(OrganizationActor $actor, MaintenanceWindow $window, Organization $organization): bool
    {
        return $this->belongsTo($window, $organization) && $this->create($actor, $organization);
    }

    private function belongsTo(MaintenanceWindow $window, Organization $organization): bool
    {
        return $window->organization_id === $organization->id;
    }
}
