<?php

namespace App\Domain\Monitoring\Domains;

use Carbon\CarbonImmutable;

/**
 * Public registration facts of a domain, as published by its registry.
 */
final readonly class DomainRegistration
{
    public function __construct(
        public string $domain,
        public CarbonImmutable $expiresAt,
        public ?string $registrar = null,
    ) {}
}
