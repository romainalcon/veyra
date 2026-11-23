<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Access control of /metrics (ADR-048). Disabled, or enabled without a token
 * and without an allow-list, the endpoint does not exist (404). Otherwise
 * every configured restriction must pass: the client address must be in the
 * allow-list (checked against the address resolved through TRUSTED_PROXIES)
 * and the bearer token must match.
 */
class AuthorizeMetricsScrape
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('veyra.metrics.token');
        /** @var list<string> $allowedIps */
        $allowedIps = (array) config('veyra.metrics.allowed_ips', []);

        if (! config('veyra.metrics.enabled') || ($token === '' && $allowedIps === [])) {
            abort(404);
        }

        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            abort(403);
        }

        if ($token !== '') {
            $provided = (string) $request->bearerToken();

            if ($provided === '' || ! hash_equals($token, $provided)) {
                return response('Unauthorized.', 401, ['WWW-Authenticate' => 'Bearer', 'Cache-Control' => 'no-store']);
            }
        }

        return $next($request);
    }
}
