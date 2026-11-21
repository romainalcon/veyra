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
        $addresses = AddressPolicy::fromSettings($this->settings);

        $ips = $parsed->hostIsIp ? [$parsed->host] : $this->resolver->resolve($parsed->host);

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

        return new Destination($parsed, AddressPolicy::normalize($ips[0]));
    }
}
