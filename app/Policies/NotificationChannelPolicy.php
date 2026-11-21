<?php

namespace App\Policies;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationRole;

/**
 * Channels hold secrets (webhook tokens, recipient lists): only admins and
 * owners manage them. Every member may see their non-secret summary.
 */
class NotificationChannelPolicy
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

    public function update(User $user, NotificationChannel $channel, Organization $organization): bool
    {
        return $channel->organization_id === $organization->id && $this->create($user, $organization);
    }

    public function delete(User $user, NotificationChannel $channel, Organization $organization): bool
    {
        return $this->update($user, $channel, $organization);
    }

    public function test(User $user, NotificationChannel $channel, Organization $organization): bool
    {
        return $this->update($user, $channel, $organization);
    }
}
