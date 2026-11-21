<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;

/**
 * Syntactic host name rules shared by URL (HTTP) and endpoint (TCP) checks,
 * applied before any DNS lookup.
 */
final readonly class HostPolicy
{
    public function __construct(private bool $allowPrivateHostnames = false) {}

    public static function fromSettings(ProbeSettings $settings): self
    {
        return new self($settings->allowPrivateNetworks);
    }

    /**
     * @return array{host: string, isIp: bool} lower-case ASCII host, without brackets or trailing dot
     *
     * @throws DestinationRejected
     */
    public function normalize(string $host): array
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['host' => $host, 'isIp' => true];
        }

        $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $host;

        if ($ascii === false || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/', $ascii) !== 1) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'The host name is not valid.');
        }

        $host = rtrim($ascii, '.');

        // Numeric, octal or hex host names (e.g. 2130706433, 0177.0.0.1,
        // 0x7f.1) are IP addresses in disguise for some resolvers.
        $labels = explode('.', $host);
        $tld = (string) end($labels);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $tld) === 1) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'Use a regular host name or a standard IP address.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Local host names are not allowed.');
        }

        if (! $this->allowPrivateHostnames && (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan'))) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Private host names are not allowed on this instance.');
        }

        return ['host' => $host, 'isIp' => false];
    }
}
