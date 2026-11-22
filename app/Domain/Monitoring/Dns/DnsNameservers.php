<?php

namespace App\Domain\Monitoring\Dns;

use App\Domain\Monitoring\Enums\DnsResolverChoice;

/**
 * Name server addresses of a resolver choice (ADR-042). Public resolvers use
 * fixed addresses; the system resolver uses the probe worker's
 * `/etc/resolv.conf` (operator-controlled, at most three servers, 127.0.0.1
 * when none is listed, like the libc resolver).
 */
class DnsNameservers
{
    public const MAX_SYSTEM_NAMESERVERS = 3;

    public function __construct(private readonly ?string $resolvConfPath = null) {}

    /**
     * @return list<string>
     */
    public function for(DnsResolverChoice $choice): array
    {
        return $choice === DnsResolverChoice::System ? $this->system() : $choice->publicAddresses();
    }

    /**
     * @return list<string>
     */
    public function system(): array
    {
        $path = $this->resolvConfPath ?? (string) config('veyra.dns.resolv_conf', '/etc/resolv.conf');
        $contents = is_readable($path) ? (string) @file_get_contents($path, length: 65_536) : '';
        $servers = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*nameserver\s+([0-9a-fA-F:.%]+)/', $line, $matches) !== 1) {
                continue;
            }

            // Link-local IPv6 servers carry a zone ("fe80::1%eth0"), unusable in a stream URL.
            $ip = $matches[1];

            if (! str_contains($ip, '%') && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $servers[] = $ip;
            }
        }

        $servers = array_slice(array_values(array_unique($servers)), 0, self::MAX_SYSTEM_NAMESERVERS);

        return $servers === [] ? ['127.0.0.1'] : $servers;
    }
}
