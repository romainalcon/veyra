<?php

namespace App\Domain\Monitoring\Domains;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Refreshes the domain registration of enabled HTTP and DNS monitors at
 * most once a day each (ADR-043). A host is looked up once per run and its
 * result cached for a day, whatever the number of monitors (or
 * organizations) following it: registration data is public. A failed
 * lookup keeps the last known expiry date and records the reason. Runs are
 * bounded by a number of lookups and a time budget; the rest waits for the
 * next run.
 */
class RefreshDomainExpiries
{
    public const REFRESH_AFTER_HOURS = 24;

    public function __construct(private readonly RdapLookup $rdap) {}

    /**
     * @return int RDAP lookups performed (cache misses)
     */
    public function handle(?CarbonImmutable $now = null, ?int $maxLookups = null, int $budgetSeconds = 240): int
    {
        $now ??= CarbonImmutable::now();
        $maxLookups ??= max(1, (int) config('veyra.domains.lookups_per_run', 200));
        $startedAt = microtime(true);
        $lookups = 0;
        /** @var array<string, DomainRegistration|DomainLookupFailed> $results */
        $results = [];

        Monitor::query()
            ->where('enabled', true)
            ->whereIn('type', MonitorType::withDomainExpiry())
            ->with(['httpConfig', 'dnsConfig', 'domain'])
            ->chunkById(200, function ($monitors) use ($now, $maxLookups, $budgetSeconds, $startedAt, &$lookups, &$results): bool {
                foreach ($monitors as $monitor) {
                    $host = $monitor->domainHost();
                    $row = $monitor->domain;

                    if ($host === null) {
                        $row?->delete();

                        continue;
                    }

                    if ($row !== null && $row->host === $host && $row->checked_at?->greaterThan($now->subHours(self::REFRESH_AFTER_HOURS))) {
                        continue;
                    }

                    if (! isset($results[$host])) {
                        $cached = self::fromCache(Cache::get(self::cacheKey($host)));

                        if ($cached !== null) {
                            $results[$host] = $cached;
                        } elseif ($lookups >= $maxLookups || microtime(true) - $startedAt > $budgetSeconds) {
                            return false;
                        } else {
                            $lookups++;
                            $results[$host] = $this->lookup($host);
                        }
                    }

                    $this->store($monitor, $row, $host, $results[$host], $now);
                }

                return true;
            });

        return $lookups;
    }

    public static function cacheKey(string $host): string
    {
        return 'veyra:rdap:host:'.hash('sha256', $host);
    }

    private static function fromCache(mixed $cached): ?DomainRegistration
    {
        if (! is_array($cached) || ! is_string($cached['domain'] ?? null) || ! is_string($cached['expires_at'] ?? null)) {
            return null;
        }

        return new DomainRegistration(
            $cached['domain'],
            CarbonImmutable::parse($cached['expires_at'])->utc(),
            is_string($cached['registrar'] ?? null) ? $cached['registrar'] : null,
        );
    }

    private function lookup(string $host): DomainRegistration|DomainLookupFailed
    {
        try {
            $registration = $this->rdap->lookup($host);
        } catch (DomainLookupFailed $exception) {
            return $exception;
        }

        // Plain values only: the cache never unserializes objects.
        Cache::put(self::cacheKey($host), [
            'domain' => $registration->domain,
            'expires_at' => $registration->expiresAt->toIso8601String(),
            'registrar' => $registration->registrar,
        ], now()->addHours(self::REFRESH_AFTER_HOURS - 1));

        return $registration;
    }

    private function store(Monitor $monitor, ?MonitorDomain $row, string $host, DomainRegistration|DomainLookupFailed $result, CarbonImmutable $now): void
    {
        $sameHost = $row !== null && $row->host === $host;

        $values = $result instanceof DomainRegistration
            ? ['domain' => $result->domain, 'expires_at' => $result->expiresAt, 'registrar' => $result->registrar, 'last_error' => null]
            : [
                'domain' => $sameHost ? $row->domain : null,
                'expires_at' => $sameHost ? $row->expires_at : null,
                'registrar' => $sameHost ? $row->registrar : null,
                'last_error' => mb_substr($result->getMessage(), 0, 255),
            ];

        MonitorDomain::query()->updateOrCreate(
            ['monitor_id' => $monitor->id],
            ['host' => $host, 'checked_at' => $now] + $values,
        );
    }
}
