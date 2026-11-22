<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Resolvers a DNS monitor may use (ADR-042). The set is fixed in code: an
 * organization can never point queries at an arbitrary IP address, and the
 * operator can only narrow it (`VEYRA_DNS_RESOLVERS`).
 */
enum DnsResolverChoice: string
{
    /** The name servers of the probe worker (`/etc/resolv.conf`). */
    case System = 'system';
    case Cloudflare = 'cloudflare';
    case Google = 'google';
    case Quad9 = 'quad9';

    /**
     * Fixed public resolver addresses (empty for the system resolver).
     *
     * @return list<string>
     */
    public function publicAddresses(): array
    {
        return match ($this) {
            self::System => [],
            self::Cloudflare => ['1.1.1.1', '1.0.0.1'],
            self::Google => ['8.8.8.8', '8.8.4.4'],
            self::Quad9 => ['9.9.9.9', '149.112.112.112'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::System => (string) __('System resolver'),
            self::Cloudflare => 'Cloudflare (1.1.1.1)',
            self::Google => 'Google (8.8.8.8)',
            self::Quad9 => 'Quad9 (9.9.9.9)',
        };
    }

    /**
     * Resolvers enabled on this instance, in declaration order.
     *
     * @return list<self>
     */
    public static function allowed(): array
    {
        /** @var array<int, string> $configured */
        $configured = (array) config('veyra.dns.resolvers', ['system', 'cloudflare', 'google', 'quad9']);
        $configured = array_map(fn (string $value): string => strtolower(trim($value)), $configured);

        return array_values(array_filter(self::cases(), fn (self $choice): bool => in_array($choice->value, $configured, true)));
    }
}
