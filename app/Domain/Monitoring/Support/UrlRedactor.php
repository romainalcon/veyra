<?php

namespace App\Domain\Monitoring\Support;

/**
 * Produces a log-safe representation of a URL: no credentials, no query
 * string and no fragment.
 */
final class UrlRedactor
{
    public static function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[invalid-url]';
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return "{$scheme}://{$host}{$port}{$path}";
    }

    public static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '[invalid-url]';
    }
}
