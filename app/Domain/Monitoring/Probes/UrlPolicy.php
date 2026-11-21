<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;

/**
 * Syntactic URL rules applied before any network access.
 */
class UrlPolicy
{
    public const MAX_LENGTH = 2048;

    /**
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        private readonly array $allowedPorts,
        private readonly bool $allowPrivateHostnames = false,
    ) {}

    public static function fromSettings(ProbeSettings $settings): self
    {
        return new self($settings->allowedPorts, $settings->allowPrivateNetworks);
    }

    /**
     * @throws DestinationRejected
     */
    public function parse(string $url): ParsedUrl
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'The URL is empty, too long or contains whitespace.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'The URL must be absolute, e.g. https://example.com.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'Only http and https URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'Credentials in the URL are not allowed; use a custom header instead.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $hostIsIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $hostIsIp) {
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
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, $this->allowedPorts, true)) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, "Port {$port} is not allowed on this instance.");
        }

        return new ParsedUrl($url, $scheme, $host, $port, $hostIsIp);
    }
}
