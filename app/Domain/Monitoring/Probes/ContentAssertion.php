<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\MonitorHttpConfig;
use Illuminate\Http\Client\Response;

/**
 * Keyword assertion on the response body (ADR-037). The body is the one the
 * probe already downloaded under its response cap; gzip and deflate bodies are
 * decoded under the same cap, other encodings are reported as unreadable.
 * The keyword and the body are never copied into check results or logs.
 */
final readonly class ContentAssertion
{
    public function __construct(
        public string $keyword,
        public KeywordMode $mode = KeywordMode::Contains,
        public bool $caseSensitive = false,
    ) {}

    public static function fromConfig(MonitorHttpConfig $config): ?self
    {
        if ($config->keyword === null || $config->keyword === '' || ! $config->method->hasResponseBody()) {
            return null;
        }

        return new self($config->keyword, $config->keyword_mode ?? KeywordMode::Contains, $config->keyword_case_sensitive);
    }

    /**
     * The failure reason, or null when the assertion holds.
     */
    public function evaluate(Response $response, int $maxBytes): ?ProbeErrorCode
    {
        $body = self::decodedBody($response, $maxBytes);

        if ($body === null) {
            return ProbeErrorCode::BodyUnreadable;
        }

        $found = $this->caseSensitive
            ? str_contains($body, $this->keyword)
            : mb_stripos($body, $this->keyword, 0, 'UTF-8') !== false;

        return match ($this->mode) {
            KeywordMode::Contains => $found ? null : ProbeErrorCode::KeywordMissing,
            KeywordMode::NotContains => $found ? ProbeErrorCode::KeywordFound : null,
        };
    }

    private static function decodedBody(Response $response, int $maxBytes): ?string
    {
        $raw = $response->body();
        $encoding = strtolower(trim($response->header('Content-Encoding')));

        if ($encoding === '' || $encoding === 'identity') {
            return $raw;
        }

        if (! in_array($encoding, ['gzip', 'x-gzip', 'deflate'], true)) {
            return null;
        }

        // The output is capped like the download, so a compressed bomb cannot
        // exhaust memory; a body that does not fit under the cap is unreadable.
        $decoded = @zlib_decode($raw, $maxBytes + 1);

        return $decoded === false || strlen($decoded) > $maxBytes ? null : $decoded;
    }
}
