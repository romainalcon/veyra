<?php

namespace App\Http\Presenters;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageComponent;
use App\Domain\StatusPages\Support\DomainName;

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
            'custom_url' => $statusPage->hasVerifiedDomain() ? 'https://'.$statusPage->custom_domain : null,
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
            'domain' => $statusPage->custom_domain === null ? null : [
                'name' => $statusPage->custom_domain,
                'verified' => $statusPage->hasVerifiedDomain(),
                'verified_at' => $statusPage->domain_verified_at?->toIso8601String(),
                'checked_at' => $statusPage->domain_checked_at?->toIso8601String(),
                'failures' => $statusPage->domain_failures,
                // Shown to page managers only (the edit page requires `update`).
                'txt_name' => DomainName::challengeName($statusPage->custom_domain),
                'txt_value' => DomainName::challengeValue((string) $statusPage->domain_verification_token),
            ],
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
