<?php

namespace App\Domain\Entitlements;

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Organizations\Models\Organization;

/**
 * Community edition: nothing is limited; retention follows the instance setting.
 */
class CommunityEntitlements implements Entitlements
{
    public function limit(Organization $organization, Feature $feature): ?int
    {
        return null;
    }
}
