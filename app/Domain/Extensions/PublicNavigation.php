<?php

namespace App\Domain\Extensions;

use Closure;
use InvalidArgumentException;

/**
 * Public site links (ADR-032), such as the hosted service's pricing and legal
 * pages contributed by optional extensions, or the core changelog and roadmap
 * pages shown with the landing page (ADR-049). Registered from service
 * providers and shown to everyone, signed in or not: in the landing page
 * header ("header") or in the footers of the landing, authentication and
 * application pages ("footer"). Empty on a Community instance without the
 * landing page.
 */
class PublicNavigation
{
    public const PLACEMENTS = ['header', 'footer'];

    /**
     * @var array<string, array{title: string, route: string, placement: string, visible: (Closure(): bool)|null}>
     */
    private array $items = [];

    /**
     * @param  string  $title  English source string, translated for each request
     * @param  string  $route  named route without parameters, reachable by guests
     * @param  string  $placement  one of self::PLACEMENTS
     * @param  (Closure(): bool)|null  $visible  evaluated on each request; the link is always shown without it
     */
    public function add(string $id, string $title, string $route, string $placement = 'footer', ?Closure $visible = null): void
    {
        if (! in_array($placement, self::PLACEMENTS, true)) {
            throw new InvalidArgumentException("Unknown navigation placement [{$placement}]; use one of: ".implode(', ', self::PLACEMENTS).'.');
        }

        $this->items["{$placement}.{$id}"] = ['title' => $title, 'route' => $route, 'placement' => $placement, 'visible' => $visible];
    }

    /**
     * Every registered link currently visible, in registration order.
     *
     * @return list<array{id: string, title: string, href: string, placement: string}>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->items as $key => $item) {
            if ($item['visible'] !== null && ! ($item['visible'])()) {
                continue;
            }

            $entries[] = [
                'id' => substr($key, strlen($item['placement']) + 1),
                'title' => (string) __($item['title']),
                'href' => route($item['route']),
                'placement' => $item['placement'],
            ];
        }

        return $entries;
    }
}
