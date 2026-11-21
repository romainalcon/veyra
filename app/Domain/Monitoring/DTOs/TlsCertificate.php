<?php

namespace App\Domain\Monitoring\DTOs;

use Carbon\CarbonImmutable;

/**
 * Public facts about the leaf certificate presented by an HTTPS endpoint.
 */
final readonly class TlsCertificate
{
    public function __construct(
        public string $fingerprint,
        public ?string $subject,
        public ?string $issuer,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
    ) {}
}
