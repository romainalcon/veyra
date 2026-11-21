<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Contracts\DnsResolver;

/**
 * Validates a URL, resolves it and checks every resolved address against the
 * address policy. Used both when saving a monitor and before each request
 * (including every redirect hop).
 */
class DestinationGuard
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly ProbeSettings $settings,
    ) {}

    /**
     * @throws DestinationRejected
     */
    public function inspect(string $url): Destination
    {
        $parsed = UrlPolicy::fromSettings($this->settings)->parse($url);

        return new Destination($parsed, $this->resolveAllowed($parsed->host, $parsed->hostIsIp));
    }

    /**
     * Validates a TCP endpoint: host name rules, the TCP port allow-list and
     * every resolved address.
     *
     * @throws DestinationRejected
     */
    public function inspectEndpoint(string $host, int $port): Endpoint
    {
        ['host' => $host, 'isIp' => $hostIsIp] = HostPolicy::fromSettings($this->settings)->normalize($host);

        if (! in_array($port, $this->settings->tcpAllowedPorts, true)) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, "Port {$port} is not allowed for TCP monitors on this instance.");
        }

        return new Endpoint($host, $port, $hostIsIp, $this->resolveAllowed($host, $hostIsIp));
    }

    /**
     * Every answer must be allowed (not only the one we connect to): a mixed
     * answer is a classic rebinding setup.
     *
     * @throws DestinationRejected
     */
    private function resolveAllowed(string $host, bool $hostIsIp): string
    {
        $addresses = AddressPolicy::fromSettings($this->settings);
        $ips = $hostIsIp ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new DestinationRejected(ProbeErrorCode::DnsFailure, 'The host name could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! $addresses->allows($ip)) {
                throw new DestinationRejected(
                    ProbeErrorCode::ForbiddenDestination,
                    'This destination resolves to an address that is not allowed on this instance.',
                );
            }
        }

        return AddressPolicy::normalize($ips[0]);
    }
}
