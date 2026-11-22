<?php

namespace App\Http\Presenters;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAsset;
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
            'accent_color' => $statusPage->accent_color,
            'subscriptions_enabled' => $statusPage->subscriptions_enabled,
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

    /**
     * Branding, subscriber counts (never addresses), feeds and badge URLs for
     * the edit page. Embeds use the page's public address.
     *
     * @return array<string, mixed>
     */
    public static function audience(Organization $organization, StatusPage $statusPage): array
    {
        $base = $statusPage->hasVerifiedDomain() ? 'https://'.$statusPage->custom_domain : route('status-pages.public', $statusPage->slug);
        $assets = $statusPage->assets()->get(['id', 'status_page_id', 'kind', 'mime', 'sha256', 'width', 'height', 'size', 'updated_at'])
            ->keyBy(fn (StatusPageAsset $asset): string => $asset->kind->value);
        $counts = $statusPage->subscribers()
            ->toBase()
            ->selectRaw('count(confirmed_at) as confirmed, count(*) - count(confirmed_at) as pending')
            ->first();

        return [
            'assets' => collect(AssetKind::cases())->mapWithKeys(fn (AssetKind $kind): array => [$kind->value => ($asset = $assets->get($kind->value)) === null ? null : [
                'url' => route('organizations.status-pages.assets.show', [$organization, $statusPage, $kind->value]).'?v='.substr($asset->sha256, 0, 8),
                'width' => $asset->width,
                'height' => $asset->height,
                'size' => $asset->size,
            ]])->all(),
            'limits' => collect(AssetKind::cases())->mapWithKeys(fn (AssetKind $kind): array => [$kind->value => [
                'max_kb' => intdiv($kind->maxBytes(), 1024),
                'max_dimension' => $kind->maxDimension(),
                'accept' => implode(',', array_keys($kind->mimeTypes())),
            ]])->all(),
            'subscribers' => [
                'confirmed' => (int) ($counts->confirmed ?? 0),
                'pending' => (int) ($counts->pending ?? 0),
            ],
            'feeds' => [
                'atom' => $base.'/feed.atom',
                'rss' => $base.'/feed.rss',
            ],
            'badges' => $statusPage->components
                ->map(fn (StatusPageComponent $component): array => [
                    'name' => $component->display_name,
                    'status' => "{$base}/badges/{$component->id}/status.svg",
                    'uptime' => $statusPage->show_uptime ? "{$base}/badges/{$component->id}/uptime-30d.svg" : null,
                ])
                ->values()
                ->all(),
            'public_base' => $base,
        ];
    }
}
