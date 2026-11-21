<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, unauthenticated and sessionless. Unpublished pages are 404.
 *
 * The page is resolved explicitly: the route runs without the `web` group,
 * whose exclusion also removes SubstituteBindings.
 */
class PublicStatusPageController extends Controller
{
    public const CACHE_CONTROL = 'public, max-age=30';

    public function __construct(private readonly StatusPageSnapshot $snapshot) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $statusPage = StatusPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return $this->render($request, $statusPage);
    }

    public function render(Request $request, StatusPage $statusPage): Response
    {
        abort_unless($statusPage->is_published, 404);

        $response = Inertia::render('status/Show', ['snapshot' => $this->snapshot->for($statusPage)])
            ->toResponse($request);

        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }
}
