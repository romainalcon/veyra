<?php

namespace App\Policies;

use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class MaintenanceSeriesPolicy
{
    use ResolvesOrganizationRole;

    public function create(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->canManageResources() ?? false;
    }

    public function update(User $user, MaintenanceSeries $series, Organization $organization): bool
    {
        return $series->organization_id === $organization->id && $this->create($user, $organization);
    }

    public function delete(User $user, MaintenanceSeries $series, Organization $organization): bool
    {
        return $this->update($user, $series, $organization);
    }
}
