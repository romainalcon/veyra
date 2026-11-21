<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\HttpMethod;

final readonly class HttpTargetData
{
    /**
     * @param  array<string, string>|null  $headers  null keeps the stored headers unchanged
     */
    public function __construct(
        public string $url,
        public HttpMethod $method,
        public ExpectedStatuses $expectedStatuses,
        public bool $followRedirects,
        public ?array $headers,
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
        ];

        if ($this->headers !== null) {
            $config['headers_encrypted'] = $this->headers === [] ? null : $this->headers;
        }

        return $config;
    }
}
