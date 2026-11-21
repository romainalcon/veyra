<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers. Inline scripts are allowed only through
 * the per-request Vite nonce; inline style attributes are required by the UI
 * components (reka-ui, charts) and therefore permitted.
 */
class SecurityHeaders
{
    /**
     * Paths rendering third-party dashboards with their own inline assets.
     */
    public const CSP_EXCLUDED_PATHS = ['horizon', 'horizon/*'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $request->is(self::CSP_EXCLUDED_PATHS) && ! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    public function policy(string $nonce): string
    {
        $connect = array_filter(["'self'", ...(array) config('veyra.csp.connect_src', [])]);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
