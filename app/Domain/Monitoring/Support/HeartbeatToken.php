<?php

namespace App\Domain\Monitoring\Support;

use Illuminate\Support\Str;

/**
 * Ping tokens are 40 random alphanumeric characters (~238 bits). Only their
 * SHA-256 hash is stored: a database leak does not reveal ping URLs, and the
 * plain token is shown once, on creation or regeneration.
 */
final class HeartbeatToken
{
    public const LENGTH = 40;

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token) === 1;
    }
}
