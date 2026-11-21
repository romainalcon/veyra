<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\DTOs\OrganizationData;
use App\Domain\Organizations\Models\Organization;

class UpdateOrganization
{
    /**
     * The slug is intentionally immutable so that existing links keep working.
     */
    public function handle(Organization $organization, OrganizationData $data): Organization
    {
        $organization->update(array_filter([
            'name' => $data->name,
            'locale' => $data->locale,
        ], fn (?string $value): bool => $value !== null));

        return $organization;
    }
}
