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
        $connect = $this->sources(["'self'"], 'connect_src');

        return implode('; ', [
            "default-src 'self'",
            'script-src '.$this->sources(["'self'", "'nonce-{$nonce}'"], 'script_src'),
            "style-src 'self' 'unsafe-inline'",
            'img-src '.$this->sources(["'self'", 'data:'], 'img_src'),
            "font-src 'self' data:",
            'connect-src '.$connect,
            'frame-src '.$this->sources(["'self'"], 'frame_src'),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Base sources plus the configured extra origins of a directive. Values
     * containing separators or quotes are ignored so that configuration can
     * never inject another directive.
     *
     * @param  list<string>  $base
     */
    private function sources(array $base, string $key): string
    {
        $extra = array_filter(
            array_map('strval', (array) config("veyra.csp.{$key}", [])),
            fn (string $source): bool => $source !== '' && preg_match('/[;,\s\'"]/', $source) !== 1,
        );

        return implode(' ', array_values(array_unique([...$base, ...$extra])));
    }
}
