<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\Organizations\Services\OwnershipGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    public function __construct(
        private readonly OwnershipGuard $ownership,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(OrganizationMember $member, OrganizationRole $role): OrganizationMember
    {
        return DB::transaction(function () use ($member, $role): OrganizationMember {
            $member = $this->ownership->lockAndEnsureNotLastOwner(
                $member,
                demoting: $role !== OrganizationRole::Owner,
            );

            $previous = $member->role;
            $member->update(['role' => $role]);

            $this->audit->record($member->organization_id, AuditAction::MemberRoleChanged, AuditSnapshot::memberSubject($member), before: ['role' => $previous], after: ['role' => $role]);

            return $member;
        });
    }
}
