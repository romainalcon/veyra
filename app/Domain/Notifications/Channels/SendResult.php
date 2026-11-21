<?php

namespace App\Domain\Notifications\Channels;

/**
 * Outcome of one delivery attempt. `error` is safe to store and display: it
 * never contains webhook URLs, tokens or recipient addresses.
 */
final readonly class SendResult
{
    private function __construct(
        public bool $sent,
        public bool $retryable,
        public ?string $error,
        public ?int $retryAfterSeconds,
    ) {}

    public static function sent(): self
    {
        return new self(true, false, null, null);
    }

    public static function retryable(string $error, ?int $retryAfterSeconds = null): self
    {
        return new self(false, true, $error, $retryAfterSeconds);
    }

    public static function permanent(string $error): self
    {
        return new self(false, false, $error, null);
    }
}
