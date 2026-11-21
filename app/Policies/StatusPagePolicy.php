<?php

namespace App\Policies;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Models\StatusPage;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

class StatusPagePolicy
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

    public function view(User $user, StatusPage $statusPage, Organization $organization): bool
    {
        return $this->belongsTo($statusPage, $organization) && $this->viewAny($user, $organization);
    }

    public function update(User $user, StatusPage $statusPage, Organization $organization): bool
    {
        return $this->belongsTo($statusPage, $organization) && $this->create($user, $organization);
    }

    public function delete(User $user, StatusPage $statusPage, Organization $organization): bool
    {
        return $this->update($user, $statusPage, $organization);
    }

    private function belongsTo(StatusPage $statusPage, Organization $organization): bool
    {
        return $statusPage->organization_id === $organization->id;
    }
}
