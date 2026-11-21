<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    /**
     * Accept an invitation for the given user. The invitation row is locked and
     * deleted in the same transaction, so a token can be used only once.
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $plainToken): Organization
    {
        return DB::transaction(function () use ($user, $plainToken): Organization {
            $invitation = OrganizationInvitation::query()
                ->whereToken($plainToken)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->isExpired()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is invalid or has expired.'),
                ]);
            }

            if (! hash_equals(Str::lower($invitation->email), Str::lower($user->email))) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation was sent to a different e-mail address.'),
                ]);
            }

            $organization = $invitation->organization;

            $organization->members()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role, 'joined_at' => now()],
            );

            $invitation->delete();

            return $organization;
        });
    }
}
