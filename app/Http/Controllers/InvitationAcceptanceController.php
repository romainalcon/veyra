<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\Organizations\Actions\AcceptInvitation;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public invitation landing page and acceptance endpoint. The plain token is
 * only known to the e-mail recipient; it is never rendered back as a prop
 * except within the URL the visitor already holds.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = OrganizationInvitation::query()
            ->whereToken($token)
            ->pending()
            ->with('organization:id,name')
            ->first();

        abort_if($invitation === null, 404);

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            $request->session()->put(RegistrationGate::SESSION_INVITATION_TOKEN, $token);
        }

        return Inertia::render('invitations/Show', [
            'token' => $token,
            'organizationName' => $invitation->organization->name,
            'role' => $invitation->role->label(),
            'isAuthenticated' => $user !== null,
            'emailMatches' => $user !== null && Str::lower($user->email) === $invitation->email,
        ]);
    }

    public function store(Request $request, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organization = $acceptInvitation->handle($user, $token);

        $request->session()->forget(RegistrationGate::SESSION_INVITATION_TOKEN);

        return to_route('organizations.dashboard', $organization);
    }
}
