<?php

namespace App\Domain\Identity\Services;

use App\Domain\Organizations\Models\OrganizationInvitation;
use Illuminate\Support\Str;

/**
 * Decides whether a visitor may create an account. When public registration
 * is closed, only people holding a pending invitation may register.
 */
class RegistrationGate
{
    public const SESSION_INVITATION_TOKEN = 'veyra.invitation_token';

    public function isPublic(): bool
    {
        return (bool) config('veyra.registration_enabled');
    }

    /**
     * Whether the registration form may be shown.
     */
    public function allowsForm(?string $invitationToken): bool
    {
        if ($this->isPublic()) {
            return true;
        }

        return $invitationToken !== null
            && OrganizationInvitation::query()->whereToken($invitationToken)->pending()->exists();
    }

    /**
     * Whether an account may be created for the given e-mail address.
     */
    public function allowsEmail(string $email): bool
    {
        if ($this->isPublic()) {
            return true;
        }

        return OrganizationInvitation::query()
            ->where('email', Str::lower(trim($email)))
            ->pending()
            ->exists();
    }
}
