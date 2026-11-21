<?php

namespace Tests\Support;

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Organizations\Models\Organization;

/**
 * Configurable limits for tests, optionally per organization.
 */
class FakeEntitlements implements Entitlements
{
    /**
     * @var array<string, int>
     */
    private array $limits = [];

    /**
     * @var array<string, array<string, int>>
     */
    private array $organizationLimits = [];

    public function set(Feature $feature, int $limit, ?Organization $organization = null): self
    {
        if ($organization === null) {
            $this->limits[$feature->value] = $limit;
        } else {
            $this->organizationLimits[$organization->id][$feature->value] = $limit;
        }

        return $this;
    }

    public function limit(Organization $organization, Feature $feature): ?int
    {
        return $this->organizationLimits[$organization->id][$feature->value]
            ?? $this->limits[$feature->value]
            ?? null;
    }
}
