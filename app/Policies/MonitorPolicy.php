<?php

namespace App\Policies;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Contracts\OrganizationActor;
use App\Domain\Organizations\Models\Organization;
use App\Policies\Concerns\ResolvesOrganizationRole;

class MonitorPolicy
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

    public function view(OrganizationActor $actor, Monitor $monitor, Organization $organization): bool
    {
        return $this->belongsTo($monitor, $organization) && $this->viewAny($actor, $organization);
    }

    public function update(OrganizationActor $actor, Monitor $monitor, Organization $organization): bool
    {
        return $this->belongsTo($monitor, $organization) && $this->create($actor, $organization);
    }

    public function delete(OrganizationActor $actor, Monitor $monitor, Organization $organization): bool
    {
        return $this->update($actor, $monitor, $organization);
    }

    /**
     * Heartbeats are not probed: their checks come from pings.
     */
    public function checkNow(OrganizationActor $actor, Monitor $monitor, Organization $organization): bool
    {
        return $monitor->type !== MonitorType::Heartbeat && $this->update($actor, $monitor, $organization);
    }

    private function belongsTo(Monitor $monitor, Organization $organization): bool
    {
        return $monitor->organization_id === $organization->id;
    }
}
