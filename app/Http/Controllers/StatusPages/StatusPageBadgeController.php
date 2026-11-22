<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Enums\PublicComponentStatus;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Domain\StatusPages\Support\BadgeRenderer;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * SVG badges for components of a published page, read from the whitelisted
 * snapshot only. Unknown components, other pages' components, unpublished
 * pages and uptime badges of pages hiding uptime all get the same 404.
 */
class StatusPageBadgeController extends Controller
{
    public const CACHE_CONTROL = 'public, max-age=60';

    public const BADGES = ['status', 'uptime-24h', 'uptime-7d', 'uptime-30d'];

    public function __construct(private readonly StatusPageSnapshot $snapshot) {}

    public function __invoke(string $slug, string $component, string $badge): Response
    {
        return $this->render(PublicStatusPageController::published($slug), $component, $badge);
    }

    public function render(StatusPage $statusPage, string $componentId, string $badge): Response
    {
        abort_unless($statusPage->is_published && in_array($badge, self::BADGES, true), 404);

        PublicStatusPageController::useOrganizationLocale($statusPage);
        $snapshot = $this->snapshot->for($statusPage);
        $component = $this->component($snapshot, $componentId);

        abort_if($component === null, 404);

        if ($badge === 'status') {
            $status = PublicComponentStatus::from($component['status']);
            [$background, $foreground] = BadgeRenderer::statusColors($status);
            $svg = BadgeRenderer::render($component['name'], __($status->label()), $background, $foreground);
        } else {
            abort_unless($snapshot['page']['show_uptime'] === true, 404);

            $range = substr($badge, strlen('uptime-'));
            $uptime = $component['uptime'][$range] ?? null;
            [$background, $foreground] = BadgeRenderer::uptimeColors($uptime);
            $value = $uptime === null ? __('No data') : self::percent($uptime);
            $svg = BadgeRenderer::render($component['name'], "{$value} · {$range}", $background, $foreground);
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => self::CACHE_CONTROL,
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{name: string, status: string, uptime: array<string, float|null>}|null
     */
    private function component(array $snapshot, string $componentId): ?array
    {
        foreach ($snapshot['groups'] as $group) {
            foreach ($group['components'] as $component) {
                if (hash_equals((string) $component['id'], $componentId)) {
                    return $component;
                }
            }
        }

        return null;
    }

    private static function percent(float $uptime): string
    {
        $value = $uptime >= 100.0 ? '100' : number_format(floor($uptime * 100) / 100, 2, app()->getLocale() === 'fr' ? ',' : '.', '');

        return app()->getLocale() === 'fr' ? "{$value} %" : "{$value}%";
    }
}
