<?php

namespace App\Domain\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shape of an ntfy topic URL (`https://ntfy.example.com[/prefix]/topic`).
 * The destination itself is checked by WebhookUrl (probe SSRF policy).
 */
class NtfyTopicUrl implements ValidationRule
{
    public const TOPIC_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @return array{server: string, topic: string}|null `server` keeps any path prefix, without the trailing slash
     */
    public static function parse(mixed $url): ?array
    {
        if (! ServerUrlShape::isPlain($url)) {
            return null;
        }

        /** @var string $url */
        $path = (string) parse_url($url, PHP_URL_PATH);
        $topic = substr($path, (int) strrpos($path, '/') + 1);

        if (preg_match(self::TOPIC_PATTERN, $topic) !== 1) {
            return null;
        }

        return ['server' => substr($url, 0, -strlen($topic) - 1), 'topic' => $topic];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::parse($value) === null) {
            $fail(__('Provide the topic URL, e.g. https://ntfy.sh/my-alerts (letters, digits, - and _).'));
        }
    }
}
