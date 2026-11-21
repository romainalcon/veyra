<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    /**
     * Fail early, without side effects, when the account cannot be deleted.
     *
     * @throws ValidationException
     */
    public function ensureDeletable(User $user): void
    {
        DB::transaction(fn (): array => $this->soleMemberOrganizationIds($user));
    }

    /**
     * Delete the account. Organizations where the user is the only member are
     * deleted with it; the deletion is refused while the user is the last
     * owner of an organization that still has other members.
     *
     * @throws ValidationException
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            Organization::query()->whereKey($this->soleMemberOrganizationIds($user))->delete();

            $user->delete();
        });
    }

    /**
     * Lock the user's memberships and return organizations where the user is
     * the only member.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function soleMemberOrganizationIds(User $user): array
    {
        $memberships = OrganizationMember::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->get();

        $soleMemberOrganizationIds = [];

        foreach ($memberships as $membership) {
            $members = OrganizationMember::query()
                ->where('organization_id', $membership->organization_id)
                ->lockForUpdate()
                ->get();

            if ($members->count() === 1) {
                $soleMemberOrganizationIds[] = $membership->organization_id;

                continue;
            }

            $otherOwners = $members
                ->where('role', OrganizationRole::Owner)
                ->where('user_id', '!=', $user->id);

            if ($membership->role === OrganizationRole::Owner && $otherOwners->isEmpty()) {
                throw ValidationException::withMessages([
                    'password' => __('Transfer ownership of your organizations before deleting your account.'),
                ]);
            }
        }

        return $soleMemberOrganizationIds;
    }
}
