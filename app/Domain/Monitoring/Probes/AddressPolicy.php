<?php

namespace App\Domain\Monitoring\Probes;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether a resolved IP address may be probed.
 */
class AddressPolicy
{
    /**
     * Never reachable, whatever the operator settings.
     */
    public const ALWAYS_BLOCKED = [
        // IPv4
        '0.0.0.0/8',          // "this" network
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, incl. cloud metadata 169.254.169.254
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + broadcast
        '100.100.100.200/32', // Alibaba Cloud metadata (inside CGNAT)
        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::/96',              // IPv4-compatible (deprecated)
        '::ffff:0:0/96',      // IPv4-mapped (normalized before checking)
        '64:ff9b::/96',       // NAT64
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001::/32',          // Teredo
        '2001:2::/48',        // benchmarking
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
        'fd00:ec2::254/128',  // AWS IMDS over IPv6 (inside ULA)
    ];

    /**
     * Private networks, reachable only when the operator opts in.
     */
    public const PRIVATE_NETWORKS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',      // carrier-grade NAT
        'fc00::/7',           // unique local addresses
    ];

    public function __construct(private readonly bool $allowPrivateNetworks) {}

    public static function fromSettings(ProbeSettings $settings): self
    {
        return new self($settings->allowPrivateNetworks);
    }

    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ip = self::normalize($ip);

        if (IpUtils::checkIp($ip, self::ALWAYS_BLOCKED)) {
            return false;
        }

        if (IpUtils::checkIp($ip, self::PRIVATE_NETWORKS)) {
            return $this->allowPrivateNetworks;
        }

        return true;
    }

    /**
     * Convert IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) to plain IPv4 so
     * they cannot bypass IPv4 rules.
     */
    public static function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop($packed);
    }
}
