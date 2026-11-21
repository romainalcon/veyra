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
    public function __construct(private readonly array $allowedPorts) {}

    public static function fromSettings(ProbeSettings $settings): self
    {
        return new self($settings->allowedPorts);
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
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, $this->allowedPorts, true)) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, "Port {$port} is not allowed on this instance.");
        }

        return new ParsedUrl($url, $scheme, $host, $port, $hostIsIp);
    }
}
