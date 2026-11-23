<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    public function __construct(
        private readonly Quota $quota,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Accept an invitation for the given user. The invitation row is locked and
     * deleted in the same transaction, so a token can be used only once.
     * Joining is refused when the member limit was lowered since the invitation.
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

            if (! $organization->members()->where('user_id', $user->id)->exists()) {
                try {
                    $this->quota->ensureCanAdd($organization, Feature::Members, fn (): int => $organization->members()->count());
                } catch (LimitReached $exception) {
                    throw ValidationException::withMessages(['invitation' => $exception->translatedMessage()]);
                }
            }

            $member = $organization->members()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role, 'joined_at' => now()],
            );

            if ($member->wasRecentlyCreated) {
                $this->audit->record($organization, AuditAction::MemberJoined, AuditSnapshot::memberSubject($member), after: ['role' => $member->role]);
            }

            $invitation->delete();

            return $organization;
        });
    }
}
