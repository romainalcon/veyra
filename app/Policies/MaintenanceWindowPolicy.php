<?php

namespace App\Policies;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class MaintenanceWindowPolicy
{
    use ResolvesOrganizationRole;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canManageResources() ?? false;
    }

    public function view(User $user, MaintenanceWindow $window, Organization $organization): bool
    {
        return $this->belongsTo($window, $organization) && $this->viewAny($user, $organization);
    }

    public function update(User $user, MaintenanceWindow $window, Organization $organization): bool
    {
        return $this->belongsTo($window, $organization) && $this->create($user, $organization);
    }

    private function belongsTo(MaintenanceWindow $window, Organization $organization): bool
    {
        return $window->organization_id === $organization->id;
    }
}
