<?php

namespace App\Domain\Organizations\Support;

use Illuminate\Support\Str;

/**
 * Plain API tokens are `veyra_` followed by 40 random alphanumeric characters
 * (~238 bits). The prefix lets secret scanners recognize leaked tokens. Only
 * the SHA-256 hash is stored; the token is shown once, on creation.
 */
final class ApiTokenSecret
{
    public const PREFIX = 'veyra_';

    public const RANDOM_LENGTH = 40;

    public static function generate(): string
    {
        return self::PREFIX.Str::random(self::RANDOM_LENGTH);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^'.self::PREFIX.'[A-Za-z0-9]{'.self::RANDOM_LENGTH.'}$/', $token) === 1;
    }
}
