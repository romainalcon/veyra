<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Maintenance\Actions\MaterializeMaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Organizations\DTOs\OrganizationData;
use App\Domain\Organizations\Models\Organization;

class UpdateOrganization
{
    public function __construct(
        private readonly MaterializeMaintenanceSeries $materializeMaintenanceSeries,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The slug is intentionally immutable so that existing links keep working.
     * A new time zone regenerates the future occurrences of recurring
     * maintenance (ADR-041); started and past ones keep their instants.
     */
    public function handle(Organization $organization, OrganizationData $data): Organization
    {
        $before = AuditSnapshot::organization($organization);

        $organization->update(array_filter([
            'name' => $data->name,
            'locale' => $data->locale,
            'timezone' => $data->timezone,
        ], fn (?string $value): bool => $value !== null));

        $this->audit->record($organization, AuditAction::OrganizationUpdated, AuditSnapshot::organizationSubject($organization), $before, AuditSnapshot::organization($organization));

        if ($organization->wasChanged('timezone')) {
            $organization->maintenanceSeries()->each(
                fn (MaintenanceSeries $series): int => $this->materializeMaintenanceSeries->regenerate($series),
            );
        }

        return $organization;
    }
}
