<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Models\OrganizationInvitation;

class RevokeInvitation
{
    public function handle(OrganizationInvitation $invitation): void
    {
        $invitation->delete();
    }
}
