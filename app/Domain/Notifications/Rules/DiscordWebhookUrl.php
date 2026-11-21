<?php

namespace App\Domain\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only genuine Discord webhook URLs are accepted, so a notification channel
 * can never be used to reach arbitrary (internal) hosts.
 */
class DiscordWebhookUrl implements ValidationRule
{
    public const ALLOWED_HOSTS = ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'];

    public static function isValid(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 512) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && preg_match('#^/api/webhooks/\d{5,25}/[A-Za-z0-9_\-]{10,100}$#', $parts['path'] ?? '') === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Provide a Discord webhook URL (https://discord.com/api/webhooks/…).');
        }
    }
}
