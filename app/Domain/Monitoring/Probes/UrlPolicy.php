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

        ['host' => $host, 'isIp' => $hostIsIp] = (new HostPolicy($this->allowPrivateHostnames))->normalize($parts['host']);

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, $this->allowedPorts, true)) {
            throw new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Port :port is not allowed on this instance.', ['port' => $port]);
        }

        return new ParsedUrl($url, $scheme, $host, $port, $hostIsIp);
    }
}
