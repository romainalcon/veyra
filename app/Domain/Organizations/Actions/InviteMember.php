<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Localization\SupportedLocales;
use App\Domain\Organizations\DTOs\InvitationData;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Notifications\OrganizationInvitationNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteMember
{
    public function __construct(private readonly Quota $quota) {}

    /**
     * Create or refresh the pending invitation for this e-mail and send it.
     *
     * Only a SHA-256 hash of the token is stored; the plain token exists solely
     * in the e-mailed link. Pending invitations count against the member
     * limit; refreshing the invitation of the same e-mail does not add one.
     */
    public function handle(Organization $organization, User $inviter, InvitationData $data): OrganizationInvitation
    {
        $plainToken = Str::random(64);

        $invitation = DB::transaction(function () use ($organization, $inviter, $data, $plainToken): OrganizationInvitation {
            $this->quota->ensureCanAdd($organization, Feature::Members, fn (): int => $organization->members()->count()
                + $organization->invitations()->pending()->where('email', '!=', $data->email)->count());

            return OrganizationInvitation::updateOrCreate(
                ['organization_id' => $organization->id, 'email' => $data->email],
                [
                    'role' => $data->role,
                    'token_hash' => OrganizationInvitation::hashToken($plainToken),
                    'invited_by' => $inviter->id,
                    'expires_at' => now()->addHours((int) config('veyra.invitations.ttl_hours')),
                ],
            );
        });

        Notification::route('mail', $data->email)
            ->notify((new OrganizationInvitationNotification($organization->name, $inviter->name, $data->role, $plainToken))
                ->locale(SupportedLocales::resolve($organization->locale)));

        return $invitation;
    }
}
