<?php

namespace App\Domain\Monitoring\Domains;

use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\PinnedConnection;
use App\Domain\Monitoring\Probes\ProbeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the expiry date of a domain through RDAP (RFC 9082/9083, ADR-043).
 *
 * The RDAP server of a top-level domain comes from the IANA bootstrap
 * registry (RFC 9224, cached one day); only its HTTPS base URLs are used.
 * Every request goes through the probe destination policy, is pinned to the
 * validated address, follows no redirect and is bounded in time and size.
 * The registered domain is found without a public suffix list: the host is
 * tried with at most three labels, then two ("www.example.co.uk" →
 * "example.co.uk"; "www.example.com" → "www.example.com", "example.com").
 */
class RdapLookup
{
    public const TIMEOUT_SECONDS = 10;

    public const MAX_RESPONSE_BYTES = 262_144;

    public const MAX_BOOTSTRAP_BYTES = 1_048_576;

    public const BOOTSTRAP_CACHE_KEY = 'veyra:rdap:bootstrap';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly DestinationGuard $guard,
        private readonly ProbeSettings $settings,
    ) {}

    /**
     * @param  string  $host  lower-case ASCII host name
     *
     * @throws DomainLookupFailed
     */
    public function lookup(string $host): DomainRegistration
    {
        $labels = explode('.', $host);
        $baseUrl = $this->bootstrap()[(string) end($labels)] ?? null;

        if ($baseUrl === null) {
            throw new DomainLookupFailed('No RDAP service is published for this top-level domain.');
        }

        for ($count = min(3, count($labels)); $count >= 2; $count--) {
            $candidate = implode('.', array_slice($labels, -$count));
            $response = $this->get($baseUrl.'domain/'.rawurlencode($candidate), self::MAX_RESPONSE_BYTES);

            if ($response->status() === 404) {
                continue;
            }

            if ($response->status() === 429) {
                throw new DomainLookupFailed('The RDAP service rate-limited the request.');
            }

            if (! $response->successful()) {
                throw new DomainLookupFailed('The RDAP service returned an error.');
            }

            return self::parse($response->json(), $candidate);
        }

        throw new DomainLookupFailed('The domain was not found in its registry.');
    }

    /**
     * @throws DomainLookupFailed
     */
    public static function parse(mixed $document, string $candidate): DomainRegistration
    {
        if (! is_array($document)) {
            throw new DomainLookupFailed('The RDAP response could not be read.');
        }

        $expiresAt = null;

        foreach ((array) ($document['events'] ?? []) as $event) {
            if (is_array($event) && ($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null)) {
                try {
                    $expiresAt = CarbonImmutable::parse($event['eventDate'])->utc();
                } catch (Throwable) {
                    $expiresAt = null;
                }
            }
        }

        if ($expiresAt === null) {
            throw new DomainLookupFailed('The registry does not publish an expiry date for this domain.');
        }

        $name = is_string($document['ldhName'] ?? null) ? rtrim(strtolower($document['ldhName']), '.') : $candidate;

        return new DomainRegistration(
            domain: preg_match('/^[a-z0-9.-]{1,253}$/', $name) === 1 ? $name : $candidate,
            expiresAt: $expiresAt,
            registrar: self::registrar($document),
        );
    }

    /**
     * Top-level domain => HTTPS base URL ending with a slash.
     *
     * @return array<string, string>
     *
     * @throws DomainLookupFailed
     */
    public function bootstrap(): array
    {
        /** @var array<string, string>|null $cached */
        $cached = Cache::get(self::BOOTSTRAP_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->get((string) config('veyra.domains.bootstrap_url', 'https://data.iana.org/rdap/dns.json'), self::MAX_BOOTSTRAP_BYTES);
        $services = $response->successful() ? $response->json('services') : null;

        if (! is_array($services)) {
            throw new DomainLookupFailed('The RDAP bootstrap registry could not be read.');
        }

        $map = [];

        foreach ($services as $service) {
            if (! is_array($service) || ! is_array($service[0] ?? null) || ! is_array($service[1] ?? null)) {
                continue;
            }

            $url = collect($service[1])->first(fn (mixed $url): bool => is_string($url) && str_starts_with(strtolower($url), 'https://'));

            if (! is_string($url)) {
                continue;
            }

            foreach ($service[0] as $tld) {
                if (is_string($tld) && $tld !== '') {
                    $map[strtolower($tld)] = rtrim($url, '/').'/';
                }
            }
        }

        if ($map === []) {
            throw new DomainLookupFailed('The RDAP bootstrap registry could not be read.');
        }

        Cache::put(self::BOOTSTRAP_CACHE_KEY, $map, now()->addDay());

        return $map;
    }

    /**
     * @throws DomainLookupFailed
     */
    private function get(string $url, int $maxBytes): Response
    {
        try {
            $destination = $this->guard->inspect($url);
        } catch (DestinationRejected) {
            throw new DomainLookupFailed('The RDAP service address is not allowed.');
        }

        if ($destination->url->scheme !== 'https') {
            throw new DomainLookupFailed('The RDAP service address is not allowed.');
        }

        try {
            $response = $this->http
                ->withOptions(PinnedConnection::options($destination, self::TIMEOUT_SECONDS, 5, $maxBytes, ['https']))
                ->withUserAgent($this->settings->userAgent)
                ->accept('application/rdap+json, application/json')
                ->get($url);
        } catch (Throwable) {
            throw new DomainLookupFailed('The RDAP service could not be reached.');
        }

        if ($response->redirect()) {
            throw new DomainLookupFailed('The RDAP service redirected the request; redirects are not followed.');
        }

        return $response;
    }

    /**
     * Name of the entity with the "registrar" role (jCard `fn`), if any.
     *
     * @param  array<mixed>  $document
     */
    private static function registrar(array $document): ?string
    {
        foreach ((array) ($document['entities'] ?? []) as $entity) {
            if (! is_array($entity) || ! in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($entity['vcardArray'][1] ?? []) as $property) {
                if (is_array($property) && ($property[0] ?? null) === 'fn' && is_string($property[3] ?? null) && trim($property[3]) !== '') {
                    return mb_substr(trim($property[3]), 0, 255);
                }
            }
        }

        return null;
    }
}
