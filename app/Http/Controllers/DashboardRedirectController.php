<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Http\Middleware\ResolveOrganization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entry point after login: sends the user to a pending invitation, their last
 * visited organization, their first organization, or the onboarding page.
 */
class DashboardRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pendingToken = $request->session()->pull(RegistrationGate::SESSION_INVITATION_TOKEN);

        if (is_string($pendingToken) && OrganizationInvitation::query()->whereToken($pendingToken)->pending()->exists()) {
            return to_route('invitations.show', ['token' => $pendingToken]);
        }

        $lastSlug = $request->session()->get(ResolveOrganization::SESSION_LAST_ORGANIZATION);

        $organization = (is_string($lastSlug)
            ? $user->organizations()->where('organizations.slug', $lastSlug)->first()
            : null) ?? $user->organizations()->orderBy('organizations.name')->first();

        if ($organization === null) {
            return to_route('organizations.create');
        }

        return to_route('organizations.dashboard', $organization);
    }
}
