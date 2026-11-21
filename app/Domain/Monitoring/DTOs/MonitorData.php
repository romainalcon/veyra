<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorInterval;

final readonly class MonitorData
{
    /**
     * @param  array<string, string>|null  $headers  null keeps the stored headers unchanged
     */
    public function __construct(
        public string $name,
        public string $url,
        public HttpMethod $method,
        public MonitorInterval $interval,
        public int $timeoutSeconds,
        public ExpectedStatuses $expectedStatuses,
        public bool $followRedirects,
        public ?array $headers,
    ) {}
}
