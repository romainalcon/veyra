<?php

namespace App\Http\Middleware;

use App\Domain\StatusPages\Services\CustomDomainRegistry;
use App\Domain\StatusPages\Support\DomainName;
use App\Http\Controllers\StatusPages\PublicStatusPageController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests whose Host is a verified custom domain only ever reach the public
 * status page (on "/"); every other path is 404 and the application (login,
 * dashboards, session cookies) is never served on such a host. Any other
 * host — the instance itself, an IP, localhost — passes through unchanged.
 */
class ServeStatusPageForCustomDomain
{
    public function __construct(private readonly CustomDomainRegistry $registry) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($host === DomainName::appHost() || ! DomainName::isValidHostName($host)) {
            return $next($request);
        }

        $statusPage = $this->registry->pageFor($host);

        if ($statusPage === null) {
            return $next($request);
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->path() !== '/') {
            abort(404);
        }

        return app(Pipeline::class)
            ->send($request)
            ->through([
                ThrottleRequests::class.':status-pages',
                HandleAppearance::class,
                HandlePublicInertiaRequests::class,
            ])
            ->then(fn (Request $request): Response => app(PublicStatusPageController::class)->render($request, $statusPage));
    }
}
