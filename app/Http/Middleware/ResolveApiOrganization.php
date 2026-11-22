<?php

namespace App\Http\Middleware;

use App\Domain\Localization\SupportedLocales;
use App\Domain\Organizations\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API counterpart of ResolveOrganization (ADR-044): the tenant is the
 * token's organization, never a URL parameter. It is prepended to the route
 * parameters before SubstituteBindings, so scoped bindings resolve every
 * `{monitor}`, `{incident}`… through the organization's relations: another
 * organization's identifier is a plain 404. Messages follow the organization
 * language, as in the interface (ADR-027).
 */
class ResolveApiOrganization
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user('api');
        $route = $request->route();

        abort_unless($token instanceof ApiToken && $route instanceof Route, 401);

        $organization = $token->organization;

        $route->parameters = ['organization' => $organization, ...($route->parameters ?? [])];
        $request->attributes->set('currentOrganization', $organization);
        app()->setLocale(SupportedLocales::resolve($organization->locale));

        return $next($request);
    }
}
