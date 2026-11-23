<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Requires (or stops requiring) two-factor authentication for every member (ADR-040).
 */
class SetTwoFactorRequirement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Organization $organization, bool $required): Organization
    {
        return DB::transaction(function () use ($organization, $required): Organization {
            $previous = $organization->require_two_factor;
            $organization->update(['require_two_factor' => $required]);

            $this->audit->record(
                $organization,
                AuditAction::TwoFactorRequirementChanged,
                AuditSnapshot::organizationSubject($organization),
                before: ['require_two_factor' => $previous],
                after: ['require_two_factor' => $required],
            );

            return $organization;
        });
    }
}
