<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atom and RSS feeds of public incidents and maintenance, built from the
 * whitelisted snapshot only, in the organization language.
 */
class StatusPageFeedController extends Controller
{
    public const CACHE_CONTROL = 'public, max-age=300';

    public const FORMATS = ['atom' => 'application/atom+xml', 'rss' => 'application/rss+xml'];

    public function __construct(private readonly StatusPageSnapshot $snapshot) {}

    public function __invoke(Request $request, string $slug, string $format): Response
    {
        $statusPage = PublicStatusPageController::published($slug);

        return $this->render($request, $statusPage, $format, '/status/'.$statusPage->slug);
    }

    public function render(Request $request, StatusPage $statusPage, string $format, string $basePath): Response
    {
        abort_unless($statusPage->is_published && array_key_exists($format, self::FORMATS), 404);

        PublicStatusPageController::useOrganizationLocale($statusPage);
        $snapshot = $this->snapshot->for($statusPage);
        $pageUrl = $request->getSchemeAndHttpHost().($basePath === '' ? '/' : $basePath);
        $entries = $this->entries($snapshot, $pageUrl);

        $body = view("feeds.{$format}", [
            'title' => $snapshot['page']['title'],
            'description' => $snapshot['page']['description'],
            'pageUrl' => $pageUrl,
            'feedUrl' => $request->getSchemeAndHttpHost().$basePath.'/feed.'.$format,
            'locale' => app()->getLocale(),
            'updated' => collect($entries)->max('updated') ?? CarbonImmutable::parse($snapshot['generated_at']),
            'entries' => $entries,
        ])->render();

        return response($body, 200, [
            'Content-Type' => self::FORMATS[$format].'; charset=UTF-8',
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: string, title: string, link: string, published: CarbonImmutable, updated: CarbonImmutable, content: string}>
     */
    private function entries(array $snapshot, string $pageUrl): array
    {
        $labels = ['investigating' => __('Investigating'), 'update' => __('Update'), 'resolved' => __('Resolved')];
        $entries = [];

        foreach ($snapshot['incidents'] as $incident) {
            $updates = $incident['updates'];
            $lines = array_map(
                fn (array $update): string => $labels[$update['type']].' · '.self::date($update['at']).($update['message'] ? "\n".$update['message'] : ''),
                $updates,
            );

            $entries[] = [
                'id' => 'urn:veyra:status:'.$incident['key'],
                'title' => $incident['title'].($incident['status'] === 'resolved' ? ' ('.__('Resolved').')' : ''),
                'link' => $pageUrl,
                'published' => CarbonImmutable::parse($incident['started_at']),
                'updated' => CarbonImmutable::parse($updates[0]['at'] ?? $incident['resolved_at'] ?? $incident['started_at']),
                'content' => implode("\n\n", $lines),
            ];
        }

        foreach ($snapshot['maintenance'] as $window) {
            $entries[] = [
                'id' => 'urn:veyra:status:'.$window['key'],
                'title' => __('Planned maintenance — :title', ['title' => $window['title']]),
                'link' => $pageUrl,
                'published' => CarbonImmutable::parse($window['starts_at']),
                'updated' => CarbonImmutable::parse($window['starts_at']),
                'content' => trim(self::date($window['starts_at']).' → '.self::date($window['ends_at'])
                    .($window['components'] !== [] ? ' · '.implode(', ', $window['components']) : '')
                    ."\n\n".($window['description'] ?? '')),
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['updated'] <=> $a['updated']);

        return $entries;
    }

    private static function date(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i').' UTC';
    }
}
