<?php

namespace App\Domain\Monitoring\Probes;

/**
 * A URL whose every resolved address was validated. `ip` is the address the
 * connection must be pinned to.
 */
final readonly class Destination
{
    public function __construct(
        public ParsedUrl $url,
        public string $ip,
    ) {}
}
