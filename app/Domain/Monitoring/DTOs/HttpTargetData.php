<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\RequestContentType;

final readonly class HttpTargetData
{
    /**
     * Secrets (headers, body, basic auth) use `null` for "keep the stored
     * value" so they never need to be sent back to the browser.
     *
     * @param  array<string, string>|null  $headers  null keeps the stored headers unchanged
     * @param  array{content: string, content_type: RequestContentType}|false|null  $body  null keeps, false removes
     * @param  array{username: string, password: string}|false|null  $basicAuth  null keeps, false removes
     */
    public function __construct(
        public string $url,
        public HttpMethod $method,
        public ExpectedStatuses $expectedStatuses,
        public bool $followRedirects,
        public ?array $headers,
        public array|false|null $body = null,
        public array|false|null $basicAuth = null,
        public ?string $keyword = null,
        public KeywordMode $keywordMode = KeywordMode::Contains,
        public bool $keywordCaseSensitive = false,
        public ?int $latencyThresholdMs = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        $config = [
            'url' => $this->url,
            'method' => $this->method,
            'expected_statuses' => $this->expectedStatuses,
            'follow_redirects' => $this->followRedirects,
            'keyword' => $this->method->hasResponseBody() ? $this->keyword : null,
            'keyword_mode' => $this->method->hasResponseBody() && $this->keyword !== null ? $this->keywordMode : null,
            'keyword_case_sensitive' => $this->method->hasResponseBody() && $this->keyword !== null && $this->keywordCaseSensitive,
            'latency_threshold_ms' => $this->latencyThresholdMs,
        ];

        if ($this->headers !== null) {
            $config['headers_encrypted'] = $this->headers === [] ? null : $this->headers;
        }

        if (! $this->method->allowsBody() || $this->body === false) {
            $config['body_encrypted'] = null;
            $config['body_content_type'] = null;
        } elseif ($this->body !== null) {
            $config['body_encrypted'] = $this->body['content'];
            $config['body_content_type'] = $this->body['content_type'];
        }

        if ($this->basicAuth !== null) {
            $config['basic_auth_encrypted'] = $this->basicAuth === false ? null : $this->basicAuth;
        }

        return $config;
    }
}
