<?php

namespace App\Http\Presenters;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageComponent;

final class StatusPagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(StatusPage $statusPage): array
    {
        return [
            'id' => $statusPage->id,
            'title' => $statusPage->title,
            'slug' => $statusPage->slug,
            'is_published' => $statusPage->is_published,
            'components_count' => (int) ($statusPage->components_count ?? $statusPage->components()->count()),
            'public_url' => route('status-pages.public', $statusPage->slug),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(StatusPage $statusPage): array
    {
        return [
            ...self::summary($statusPage),
            'description' => $statusPage->description,
            'show_uptime' => $statusPage->show_uptime,
            'components' => $statusPage->components
                ->map(fn (StatusPageComponent $component): array => [
                    'monitor_id' => $component->monitor_id,
                    'display_name' => $component->display_name,
                    'group_name' => $component->group_name,
                ])
                ->values()
                ->all(),
        ];
    }
}
