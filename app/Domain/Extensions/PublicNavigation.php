<?php

namespace App\Domain\Extensions;

use InvalidArgumentException;

/**
 * Public site links contributed by optional extensions (ADR-032), such as the
 * hosted service's pricing and legal pages. Registered from their service
 * providers and shown to everyone, signed in or not: in the landing page
 * header ("header") or in the footers of the landing, authentication and
 * application pages ("footer"). Empty on the Community edition.
 */
class PublicNavigation
{
    public const PLACEMENTS = ['header', 'footer'];

    /**
     * @var array<string, array{title: string, route: string, placement: string}>
     */
    private array $items = [];

    /**
     * @param  string  $title  English source string, translated for each request
     * @param  string  $route  named route without parameters, reachable by guests
     * @param  string  $placement  one of self::PLACEMENTS
     */
    public function add(string $id, string $title, string $route, string $placement = 'footer'): void
    {
        if (! in_array($placement, self::PLACEMENTS, true)) {
            throw new InvalidArgumentException("Unknown navigation placement [{$placement}]; use one of: ".implode(', ', self::PLACEMENTS).'.');
        }

        $this->items["{$placement}.{$id}"] = ['title' => $title, 'route' => $route, 'placement' => $placement];
    }

    /**
     * Every registered link, in registration order.
     *
     * @return list<array{id: string, title: string, href: string, placement: string}>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->items as $key => $item) {
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
