<?php

namespace App\Domain\Notifications\Rules;

use App\Domain\Monitoring\Probes\UrlPolicy;

/**
 * Syntactic shape shared by self-hosted server URLs (ntfy, Gotify): an
 * absolute http(s) URL without credentials, query string or fragment.
 */
final class ServerUrlShape
{
    public static function isPlain(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > UrlPolicy::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! str_contains($url, '?')
            && ! str_contains($url, '#');
    }
}
