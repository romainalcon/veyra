<?php

namespace App\Http\Middleware;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after ResolveOrganization. When the organization requires two-factor
 * authentication (ADR-040), members without a confirmed second factor are
 * sent to their security settings; their other organizations stay
 * reachable. Leaving the organization is always allowed.
 */
class EnsureOrganizationTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get('currentOrganization');
        $user = $request->user();

        if (! $organization instanceof Organization
            || ! $user instanceof User
            || ! $organization->require_two_factor
            || $user->hasEnabledTwoFactorAuthentication()
            || $this->isLeaving($request, $user)) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __(':organization requires two-factor authentication. Enable it to continue.', ['organization' => $organization->name]),
        ]);

        return redirect()->route('security.edit');
    }

    private function isLeaving(Request $request, User $user): bool
    {
        $member = $request->route('member');

        return $request->routeIs('organizations.members.destroy')
            && $member instanceof OrganizationMember
            && $member->user_id === $user->id;
    }
}
