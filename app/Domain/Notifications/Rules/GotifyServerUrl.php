<?php

namespace App\Domain\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shape of a Gotify server URL (`https://gotify.example.com[/prefix]`).
 * The destination itself is checked by WebhookUrl (probe SSRF policy).
 */
class GotifyServerUrl implements ValidationRule
{
    public static function isValid(mixed $url): bool
    {
        return ServerUrlShape::isPlain($url);
    }

    /**
     * Endpoint that creates a message, below any path prefix.
     */
    public static function messageUrl(string $server): string
    {
        return rtrim($server, '/').'/message';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('Provide the Gotify server URL, e.g. https://gotify.example.com, without query string.'));
        }
    }
}
