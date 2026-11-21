<?php

namespace App\Http\Middleware;

use App\Domain\Localization\SupportedLocales;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the `{organization}` route parameter and ensures the
 * authenticated user is a member. Non-members receive a 404 so that the
 * existence of other organizations is never disclosed. Members then see the
 * interface in the organization language (ADR-027).
 */
class ResolveOrganization
{
    public const SESSION_LAST_ORGANIZATION = 'veyra.last_organization';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');
        $user = $request->user();

        abort_unless($organization instanceof Organization && $user instanceof User, 404);

        $membership = $user->membershipIn($organization);

        abort_if($membership === null, 404);

        $organization->setRelation('viewerMembership', $membership);
        $request->attributes->set('currentOrganization', $organization);
        app()->setLocale(SupportedLocales::resolve($organization->locale));
        $request->session()->put(self::SESSION_LAST_ORGANIZATION, $organization->slug);

        return $next($request);
    }
}
