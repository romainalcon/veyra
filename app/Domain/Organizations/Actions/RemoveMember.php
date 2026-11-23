<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\Organizations\Services\OwnershipGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveMember
{
    public function __construct(
        private readonly OwnershipGuard $ownership,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Remove a member (or let a member leave).
     *
     * @throws ValidationException
     */
    public function handle(OrganizationMember $member): void
    {
        DB::transaction(function () use ($member): void {
            $locked = $this->ownership->lockAndEnsureNotLastOwner($member, demoting: true);
            $leaving = $locked->user_id === Auth::id();

            $this->audit->record(
                $locked->organization_id,
                $leaving ? AuditAction::MemberLeft : AuditAction::MemberRemoved,
                AuditSnapshot::memberSubject($locked),
                before: ['role' => $locked->role],
            );

            $locked->delete();
        });
    }
}
