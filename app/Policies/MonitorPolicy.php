<?php

namespace App\Policies;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class MonitorPolicy
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

    public function view(User $user, Monitor $monitor, Organization $organization): bool
    {
        return $this->belongsTo($monitor, $organization) && $this->viewAny($user, $organization);
    }

    public function update(User $user, Monitor $monitor, Organization $organization): bool
    {
        return $this->belongsTo($monitor, $organization) && $this->create($user, $organization);
    }

    public function delete(User $user, Monitor $monitor, Organization $organization): bool
    {
        return $this->update($user, $monitor, $organization);
    }

    public function checkNow(User $user, Monitor $monitor, Organization $organization): bool
    {
        return $this->update($user, $monitor, $organization);
    }

    private function belongsTo(Monitor $monitor, Organization $organization): bool
    {
        return $monitor->organization_id === $organization->id;
    }
}
