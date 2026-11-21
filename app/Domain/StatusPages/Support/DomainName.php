<?php

namespace App\Domain\StatusPages\Support;

/**
 * Normalization and checks for custom status page domains.
 */
final class DomainName
{
    /**
     * Suffixes that never resolve publicly or belong to the local network.
     */
    public const FORBIDDEN_SUFFIXES = ['localhost', 'local', 'internal', 'lan', 'home.arpa', 'invalid', 'onion'];

    public const CHALLENGE_PREFIX = '_veyra-challenge.';

    public const CHALLENGE_VALUE_PREFIX = 'veyra-verification=';

    /**
     * Lowercase ASCII (punycode) form without a trailing dot, or null when
     * the value is not a syntactically valid host name.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(rtrim(trim($value), '.'));

        if ($value === '' || str_contains($value, '/') || str_contains($value, ':')) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                return null;
            }

            $value = strtolower($ascii);
        }

        return self::isValidHostName($value) ? $value : null;
    }

    public static function isValidHostName(string $host): bool
    {
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return false;
            }
        }

        // The TLD is alphabetic (or an IDN "xn--" label): rules out numeric hosts.
        return preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', (string) end($labels)) === 1;
    }

    public static function isForbidden(string $host, ?string $appHost): bool
    {
        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        if ($appHost !== null && $appHost !== '') {
            $appHost = strtolower($appHost);

            if ($host === $appHost || str_ends_with($host, '.'.$appHost)) {
                return true;
            }
        }

        return false;
    }

    public static function appHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public static function challengeName(string $domain): string
    {
        return self::CHALLENGE_PREFIX.$domain;
    }

    public static function challengeValue(string $token): string
    {
        return self::CHALLENGE_VALUE_PREFIX.$token;
    }
}
