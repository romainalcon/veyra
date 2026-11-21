<?php

namespace App\Domain\StatusPages\Services;

use App\Domain\StatusPages\Models\StatusPage;
use Illuminate\Support\Facades\Cache;

/**
 * Maps an incoming Host to the published status page that verified it.
 * Lookups (including misses) are cached briefly; every change to a domain
 * forgets its entry.
 */
class CustomDomainRegistry
{
    public const CACHE_SECONDS = 60;

    public function pageFor(string $host): ?StatusPage
    {
        $host = strtolower(rtrim($host, '.'));

        $id = Cache::remember(self::cacheKey($host), self::CACHE_SECONDS, fn (): string => (string) StatusPage::query()
            ->where('custom_domain', $host)
            ->whereNotNull('domain_verified_at')
            ->value('id'));

        if ($id === '') {
            return null;
        }

        // Re-check on the fresh row: unpublishing, revocation and deletion apply at once.
        return StatusPage::query()
            ->whereKey($id)
            ->where('custom_domain', $host)
            ->whereNotNull('domain_verified_at')
            ->where('is_published', true)
            ->first();
    }

    public function isServed(string $host): bool
    {
        return $this->pageFor($host) !== null;
    }

    public static function forget(?string $host): void
    {
        if ($host !== null && $host !== '') {
            Cache::forget(self::cacheKey(strtolower($host)));
        }
    }

    private static function cacheKey(string $host): string
    {
        return 'status-domains:'.hash('sha256', $host);
    }
}
