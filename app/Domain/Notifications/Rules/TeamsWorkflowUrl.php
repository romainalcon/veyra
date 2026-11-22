<?php

namespace App\Domain\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only Microsoft Teams workflow (Power Automate) webhook URLs are accepted,
 * so the channel can never reach arbitrary hosts (ADR-039). Legacy Office 365
 * connector URLs (`*.webhook.office.com`) are retired by Microsoft and refused.
 */
class TeamsWorkflowUrl implements ValidationRule
{
    /** Host suffixes of Power Automate workflow triggers. */
    public const ALLOWED_HOST_SUFFIXES = ['.logic.azure.com', '.api.powerplatform.com'];

    public static function isValid(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https'
            && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) === 1
            && array_filter(self::ALLOWED_HOST_SUFFIXES, fn (string $suffix): bool => str_ends_with($host, $suffix)) !== []
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['fragment'])
            && str_contains($parts['path'] ?? '', '/workflows/');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('Provide the URL of a Teams workflow "When a Teams webhook request is received".'));
        }
    }
}
