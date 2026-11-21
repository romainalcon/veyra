<?php

namespace App\Http\Middleware;

use App\Domain\Localization\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages outside an organization (authentication, profile, organization list)
 * follow the browser language. ResolveOrganization then switches to the
 * organization language on tenant routes (ADR-027).
 */
class SetRequestLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(SupportedLocales::negotiate($request));

        return $next($request);
    }
}
