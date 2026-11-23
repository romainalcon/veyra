<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Support\UrlRedactor;
use Illuminate\Http\Client\Response;

/**
 * What the monitored server answered on a failing HTTP check (ADR-050):
 * status, an allow-list of response headers and a bounded excerpt of a text
 * body. Kept only for the first failing check of an incident and shown only
 * to members on the incident page. Credentials, cookies and any header not on
 * the allow-list are never kept; binary or undecodable bodies are skipped.
 */
final readonly class FailureEvidence
{
    public const EXCERPT_BYTES = 4096;

    public const MAX_HEADERS = 20;

    public const MAX_HEADER_VALUE_LENGTH = 256;

    /**
     * Response headers that help diagnose a failure without carrying
     * credentials or session data. Everything else is dropped.
     */
    public const ALLOWED_HEADERS = [
        'age',
        'cache-control',
        'cf-cache-status',
        'cf-ray',
        'content-encoding',
        'content-language',
        'content-length',
        'content-type',
        'date',
        'etag',
        'expires',
        'last-modified',
        'location',
        'retry-after',
        'server',
        'vary',
        'via',
        'x-amz-cf-pop',
        'x-cache',
        'x-request-id',
        'x-served-by',
    ];

    public const OMITTED_HEAD = 'head';

    public const OMITTED_EMPTY = 'empty';

    public const OMITTED_BINARY = 'binary';

    public const OMITTED_ENCODED = 'encoded';

    /**
     * @param  array<string, string>  $headers  lower-case allow-listed names
     */
    public function __construct(
        public int $statusCode,
        public ?string $httpVersion,
        public ProbeErrorCode $errorCode,
        public ?string $contentType,
        public array $headers,
        public ?string $bodyExcerpt,
        public bool $bodyTruncated,
        public ?string $bodyOmittedReason,
    ) {}

    public static function fromResponse(Response $response, ProbeErrorCode $errorCode, bool $hasBody, int $maxResponseBytes): self
    {
        $contentType = self::header($response, 'Content-Type');
        [$excerpt, $truncated, $omitted] = $hasBody
            ? self::excerpt($response, $contentType, $maxResponseBytes)
            : [null, false, self::OMITTED_HEAD];

        return new self(
            statusCode: $response->status(),
            httpVersion: $response->toPsrResponse()->getProtocolVersion(),
            errorCode: $errorCode,
            contentType: $contentType === null ? null : mb_substr($contentType, 0, 128),
            headers: self::allowedHeaders($response),
            bodyExcerpt: $excerpt,
            bodyTruncated: $truncated,
            bodyOmittedReason: $omitted,
        );
    }

    /**
     * Whether a content type is text that can be shown as an excerpt.
     */
    public static function isTextual(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        return str_starts_with($type, 'text/')
            || in_array($type, ['application/json', 'application/xml', 'application/javascript', 'application/x-www-form-urlencoded', 'application/problem+json', 'application/problem+xml'], true)
            || str_ends_with($type, '+json')
            || str_ends_with($type, '+xml');
    }

    /**
     * @return array<string, string>
     */
    private static function allowedHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $name = strtolower((string) $name);

            if (! in_array($name, self::ALLOWED_HEADERS, true) || count($headers) >= self::MAX_HEADERS) {
                continue;
            }

            $value = implode(', ', array_map('strval', (array) $values));

            if ($name === 'location') {
                $value = self::redactLocation($value);
            }

            $headers[$name] = self::clean($value, self::MAX_HEADER_VALUE_LENGTH);
        }

        ksort($headers);

        return $headers;
    }

    /**
     * A redirect target without credentials, query string or fragment.
     */
    private static function redactLocation(string $location): string
    {
        if (parse_url($location, PHP_URL_HOST) !== null) {
            return UrlRedactor::redact($location);
        }

        return (string) preg_replace('/[?#].*$/s', '', $location);
    }

    /**
     * @return array{0: string|null, 1: bool, 2: string|null}
     */
    private static function excerpt(Response $response, ?string $contentType, int $maxResponseBytes): array
    {
        if ($response->body() === '') {
            return [null, false, self::OMITTED_EMPTY];
        }

        if (! self::isTextual($contentType)) {
            return [null, false, self::OMITTED_BINARY];
        }

        $body = ContentAssertion::decodedBody($response, $maxResponseBytes);

        if ($body === null) {
            return [null, false, self::OMITTED_ENCODED];
        }

        $truncated = strlen($body) > self::EXCERPT_BYTES;
        $excerpt = $truncated ? substr($body, 0, self::EXCERPT_BYTES) : $body;

        // A cut may fall inside a multi-byte character: drop that partial character.
        for ($dropped = 0; $truncated && $dropped < 3 && ! mb_check_encoding($excerpt, 'UTF-8'); $dropped++) {
            $excerpt = substr($excerpt, 0, -1);
        }

        if (! mb_check_encoding($excerpt, 'UTF-8') || str_contains($excerpt, "\0")) {
            return [null, false, self::OMITTED_BINARY];
        }

        return [$excerpt, $truncated, null];
    }

    private static function header(Response $response, string $name): ?string
    {
        $value = trim($response->header($name));

        return $value === '' ? null : $value;
    }

    private static function clean(string $value, int $length): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, $length);
    }

    /**
     * @return array{status_code: int, http_version: string|null, error_code: string, content_type: string|null, headers: array<string, string>, body_excerpt: string|null, body_truncated: bool, body_omitted_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'status_code' => $this->statusCode,
            'http_version' => $this->httpVersion,
            'error_code' => $this->errorCode->value,
            'content_type' => $this->contentType,
            'headers' => $this->headers,
            'body_excerpt' => $this->bodyExcerpt,
            'body_truncated' => $this->bodyTruncated,
            'body_omitted_reason' => $this->bodyOmittedReason,
        ];
    }
}
