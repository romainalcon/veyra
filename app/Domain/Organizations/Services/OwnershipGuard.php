<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMember;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the "at least one owner" invariant. Must run inside a transaction:
 * owner rows are locked so concurrent demotions/removals are serialized.
 */
class OwnershipGuard
{
    /**
     * Lock the organization's owners and the target member, then verify that
     * the change would not leave the organization without an owner.
     *
     * @return OrganizationMember the freshly locked member row
     *
     * @throws ValidationException
     */
    public function lockAndEnsureNotLastOwner(OrganizationMember $member, bool $demoting): OrganizationMember
    {
        $ownerIds = OrganizationMember::query()
            ->where('organization_id', $member->organization_id)
            ->where('role', OrganizationRole::Owner)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        $locked = OrganizationMember::query()->lockForUpdate()->findOrFail($member->id);

        if ($demoting && $locked->role === OrganizationRole::Owner && $ownerIds->count() <= 1) {
            throw ValidationException::withMessages([
                'member' => __('An organization must keep at least one owner.'),
            ]);
        }

        return $locked;
    }
}
