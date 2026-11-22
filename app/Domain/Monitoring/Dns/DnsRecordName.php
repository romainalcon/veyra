<?php

namespace App\Domain\Monitoring\Dns;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\ProbeSettings;

/**
 * Record names a DNS monitor may query. Like host names (HostPolicy) but
 * labels may start with an underscore (`_dmarc.example.com`). IP literals,
 * `localhost` and, unless the operator allows private networks, the
 * `.local`, `.internal` and `.lan` suffixes are refused, so the system
 * resolver cannot be used to explore an internal zone by name.
 */
final readonly class DnsRecordName
{
    public function __construct(private bool $allowPrivateNames = false) {}

    public static function fromSettings(ProbeSettings $settings): self
    {
        return new self($settings->allowPrivateNetworks);
    }

    /**
     * @return string lower-case ASCII name without trailing dot
     *
     * @throws DestinationRejected
     */
    public function normalize(string $name): string
    {
        $name = rtrim(strtolower(trim($name)), '.');

        if (filter_var(trim($name, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'Enter a domain name, not an IP address.');
        }

        $ascii = function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $name) === 1
            ? idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : $name;

        if ($ascii === false || ! str_contains($ascii, '.') || preg_match('/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii) !== 1) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'The domain name is not valid.');
        }

        if ($ascii === 'localhost' || str_ends_with($ascii, '.localhost')) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Local host names are not allowed.');
        }

        if (! $this->allowPrivateNames && (str_ends_with($ascii, '.local') || str_ends_with($ascii, '.internal') || str_ends_with($ascii, '.lan') || str_ends_with($ascii, '.arpa'))) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Private host names are not allowed on this instance.');
        }

        return $ascii;
    }
}
