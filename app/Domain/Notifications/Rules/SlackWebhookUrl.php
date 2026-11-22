<?php

namespace App\Domain\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only genuine Slack incoming webhook URLs are accepted, so a notification
 * channel can never be used to reach arbitrary (internal) hosts (ADR-034).
 * Workflow Builder URLs (`/triggers/…`) expect another payload and are refused.
 */
class SlackWebhookUrl implements ValidationRule
{
    public const ALLOWED_HOSTS = ['hooks.slack.com'];

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
            && preg_match('#^/services/T[A-Z0-9]{6,15}/B[A-Z0-9]{6,15}/[A-Za-z0-9]{20,40}$#', $parts['path'] ?? '') === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('Provide a Slack incoming webhook URL (https://hooks.slack.com/services/…).'));
        }
    }
}
