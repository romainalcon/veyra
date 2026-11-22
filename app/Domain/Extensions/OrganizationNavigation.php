<?php

namespace App\Domain\Extensions;

use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use InvalidArgumentException;

/**
 * Organization navigation entries contributed by optional extensions
 * (ADR-030), registered from their service providers and shown in the
 * sidebar before "Settings". Empty on the Community edition.
 */
class OrganizationNavigation
{
    /**
     * Icons the interface can render (lucide names mapped in
     * resources/js/lib/extension-icons.ts), shared with UserNavigation.
     */
    public const ICONS = ['credit-card', 'receipt', 'chart', 'puzzle', 'shield'];

    /**
     * @var array<string, array{title: string, route: string, ability: string|null, icon: string}>
     */
    private array $items = [];

    /**
     * @param  string  $title  English source string, translated for each request
     * @param  string  $route  named route taking the organization as its parameter
     * @param  string|null  $ability  ability checked against the organization
     */
    public function add(string $id, string $title, string $route, ?string $ability = null, string $icon = 'puzzle'): void
    {
        if (! in_array($icon, self::ICONS, true)) {
            throw new InvalidArgumentException("Unknown navigation icon [{$icon}]; use one of: ".implode(', ', self::ICONS).'.');
        }

        $this->items[$id] = ['title' => $title, 'route' => $route, 'ability' => $ability, 'icon' => $icon];
    }

    /**
     * Entries the user may see in this organization.
     *
     * @return list<array{id: string, title: string, href: string, icon: string}>
     */
    public function for(Organization $organization, User $user): array
    {
        $entries = [];

        foreach ($this->items as $id => $item) {
            if ($item['ability'] !== null && ! $user->can($item['ability'], $organization)) {
                continue;
            }

            $entries[] = [
                'id' => $id,
                'title' => (string) __($item['title']),
                'href' => route($item['route'], $organization),
                'icon' => $item['icon'],
            ];
        }

        return $entries;
    }
}
