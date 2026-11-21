<?php

namespace App\Domain\Monitoring\Probes;

final readonly class ParsedUrl
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public bool $hostIsIp,
    ) {}
}
