<?php

namespace App\Domain\Notifications\Channels;

/**
 * Standard Webhooks signatures (https://www.standardwebhooks.com/): the
 * secret is `whsec_` + base64 of 32 random bytes, and the signature header is
 * `v1,` + base64(HMAC-SHA256("{id}.{timestamp}.{body}", decoded secret)).
 * Receivers can verify it with any Standard Webhooks library.
 */
final class WebhookSignature
{
    public const PREFIX = 'whsec_';

    public const KEY_BYTES = 32;

    public static function generateSecret(): string
    {
        return self::PREFIX.base64_encode(random_bytes(self::KEY_BYTES));
    }

    public static function isValidSecret(mixed $secret): bool
    {
        return is_string($secret)
            && str_starts_with($secret, self::PREFIX)
            && strlen((string) base64_decode(substr($secret, strlen(self::PREFIX)), true)) >= 24;
    }

    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        $key = (string) base64_decode(substr($secret, strlen(self::PREFIX)), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }
}
