<?php

namespace App\Http\Middleware;

use App\Domain\StatusPages\Actions\SubscribeToStatusPage;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\CustomDomainRegistry;
use App\Domain\StatusPages\Support\DomainName;
use App\Http\Controllers\StatusPages\PublicStatusPageAssetController;
use App\Http\Controllers\StatusPages\PublicStatusPageController;
use App\Http\Controllers\StatusPages\StatusPageBadgeController;
use App\Http\Controllers\StatusPages\StatusPageFeedController;
use App\Http\Controllers\StatusPages\StatusPageSubscriptionController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests whose Host is a verified custom domain only ever reach the public
 * status page (on "/") and its feeds, badges, branding images and
 * subscription form (handlerFor()); every other path is 404 and the application (login,
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

        $handler = $this->handlerFor($request, $statusPage);

        abort_if($handler === null, 404);

        [$throttle, $action] = $handler;

        return app(Pipeline::class)
            ->send($request)
            ->through([
                ThrottleRequests::class.':'.$throttle,
                HandleAppearance::class,
                HandlePublicInertiaRequests::class,
            ])
            ->then($action);
    }

    /**
     * The explicit list of paths a custom domain serves (ADR-023, ADR-036).
     *
     * @return array{0: string, 1: Closure(Request): Response}|null
     */
    private function handlerFor(Request $request, StatusPage $statusPage): ?array
    {
        $path = $request->path();
        $method = $request->method();
        $read = in_array($method, ['GET', 'HEAD'], true);

        return match (true) {
            $read && $path === '/' => ['status-pages', fn (Request $request): Response => app(PublicStatusPageController::class)->render($request, $statusPage)],
            $read && preg_match('/^feed\.(atom|rss)$/', $path, $matches) === 1 => ['status-pages', fn (Request $request): Response => app(StatusPageFeedController::class)->render($request, $statusPage, $matches[1], '')],
            $read && preg_match('/^badges\/([0-9a-f-]{36})\/('.implode('|', StatusPageBadgeController::BADGES).')\.svg$/', $path, $matches) === 1 => ['status-pages', fn (): Response => app(StatusPageBadgeController::class)->render($statusPage, $matches[1], $matches[2])],
            $read && preg_match('/^assets\/('.PublicStatusPageAssetController::FILE_PATTERN.')$/', $path, $matches) === 1 => ['status-pages', fn (): Response => app(PublicStatusPageAssetController::class)->serve($statusPage, $matches[1])],
            $method === 'POST' && $path === 'subscribe' => ['status-page-subscribe', fn (Request $request): Response => app(StatusPageSubscriptionController::class)->subscribeTo($request, $statusPage, app(SubscribeToStatusPage::class))],
            default => null,
        };
    }
}
