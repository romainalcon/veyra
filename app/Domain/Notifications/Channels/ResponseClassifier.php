<?php

namespace App\Domain\Notifications\Channels;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * ADR-017 classification shared by the HTTP channels: 2xx is sent; 408, 429
 * (with Retry-After) and 5xx are retried; redirects and other statuses fail
 * permanently. Errors name the service, never the URL or a token.
 */
final class ResponseClassifier
{
    public const DEFAULT_RETRY_AFTER_SECONDS = 30;

    public static function classify(Response $response, string $service): SendResult
    {
        $status = $response->status();

        return match (true) {
            $response->successful() => SendResult::sent(),
            $status === 429 => SendResult::retryable("Rate limited by {$service} (HTTP 429).", self::retryAfter($response->header('Retry-After'))),
            $status === 408, $status >= 500 => SendResult::retryable("{$service} error (HTTP {$status})."),
            $status >= 300 && $status < 400 => SendResult::permanent("{$service} redirected (HTTP {$status}); redirects are not followed."),
            default => SendResult::permanent("{$service} rejected the notification (HTTP {$status})."),
        };
    }

    /**
     * Seconds to wait, between 1 s and 1 h; 30 s when the value is missing.
     */
    public static function retryAfter(mixed $value): int
    {
        $seconds = is_numeric($value) ? (float) $value : self::DEFAULT_RETRY_AFTER_SECONDS;

        return (int) min(3600, max(1, ceil($seconds)));
    }

    /**
     * A provider message copied into the delivery error only when it is short
     * plain text without markup or URLs, and never when it echoes one of
     * the channel secrets.
     *
     * @param  list<string>  $secrets
     */
    public static function safeDetail(mixed $text, array $secrets = []): string
    {
        if (! is_string($text)) {
            return '';
        }

        $text = trim($text);

        foreach ($secrets as $secret) {
            if ($secret !== '' && str_contains($text, $secret)) {
                return '';
            }
        }

        return preg_match("/^[\\p{L}\\p{N} :_.,'()-]{1,120}$/u", $text) === 1 ? ": {$text}" : '';
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    public static function causedBy(Throwable $exception, string $class): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
