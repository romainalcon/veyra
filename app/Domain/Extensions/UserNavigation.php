<?php

namespace App\Domain\Extensions;

use App\Models\User;
use InvalidArgumentException;

/**
 * User menu entries contributed by optional extensions (ADR-030), registered
 * from their service providers and shown in the user menu on every page,
 * inside or outside an organization. Empty on the Community edition.
 */
class UserNavigation
{
    /**
     * @var array<string, array{title: string, route: string, ability: string|null, icon: string}>
     */
    private array $items = [];

    /**
     * @param  string  $title  English source string, translated for each request
     * @param  string  $route  named route without parameters
     * @param  string|null  $ability  ability checked for the user, without arguments
     */
    public function add(string $id, string $title, string $route, ?string $ability = null, string $icon = 'puzzle'): void
    {
        if (! in_array($icon, OrganizationNavigation::ICONS, true)) {
            throw new InvalidArgumentException("Unknown navigation icon [{$icon}]; use one of: ".implode(', ', OrganizationNavigation::ICONS).'.');
        }

        $this->items[$id] = ['title' => $title, 'route' => $route, 'ability' => $ability, 'icon' => $icon];
    }

    /**
     * Entries the user may see.
     *
     * @return list<array{id: string, title: string, href: string, icon: string}>
     */
    public function for(User $user): array
    {
        $entries = [];

        foreach ($this->items as $id => $item) {
            if ($item['ability'] !== null && ! $user->can($item['ability'])) {
                continue;
            }

            $entries[] = [
                'id' => $id,
                'title' => (string) __($item['title']),
                'href' => route($item['route']),
                'icon' => $item['icon'],
            ];
        }

        return $entries;
    }
}
