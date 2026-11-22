<?php

namespace App\Domain\Organizations\Contracts;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;

/**
 * Who performs a request inside an organization: a member (User) or an
 * organization API token (ADR-044). Policies shared by the interface and the
 * API only ask for the actor's role, so both follow the same rules.
 */
interface OrganizationActor
{
    /**
     * The actor's role in the organization; null when it has no access.
     */
    public function roleIn(Organization $organization): ?OrganizationRole;
}
