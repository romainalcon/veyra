<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Models\Organization;

class DeleteOrganization
{
    /**
     * Memberships and pending invitations are removed by foreign-key cascades.
     */
    public function handle(Organization $organization): void
    {
        $organization->delete();
    }
}
