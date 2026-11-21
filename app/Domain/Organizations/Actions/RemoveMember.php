<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\Organizations\Services\OwnershipGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveMember
{
    public function __construct(private readonly OwnershipGuard $ownership) {}

    /**
     * Remove a member (or let a member leave).
     *
     * @throws ValidationException
     */
    public function handle(OrganizationMember $member): void
    {
        DB::transaction(function () use ($member): void {
            $this->ownership->lockAndEnsureNotLastOwner($member, demoting: true)->delete();
        });
    }
}
