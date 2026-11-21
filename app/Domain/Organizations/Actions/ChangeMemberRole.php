<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\Organizations\Services\OwnershipGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    public function __construct(private readonly OwnershipGuard $ownership) {}

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

            $member->update(['role' => $role]);

            return $member;
        });
    }
}
