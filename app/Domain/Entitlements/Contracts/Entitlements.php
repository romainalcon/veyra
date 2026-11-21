<?php

namespace App\Domain\Entitlements\Contracts;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Organizations\Models\Organization;

/**
 * What an organization is entitled to. The Community core binds
 * CommunityEntitlements (no limits); an optional extension may bind its own
 * implementation. Core code never asks which edition is running (ADR-029).
 */
interface Entitlements
{
    /**
     * The limit of a feature for this organization; null means unlimited
     * (or, for value features, the instance default).
     */
    public function limit(Organization $organization, Feature $feature): ?int;
}
