<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\ProbeErrorCode;

/**
 * Raw outcome of one probe execution. This is not the confirmed monitor
 * state, which is derived from several results (Sprint 3).
 */
final readonly class ProbeResult
{
    /**
     * @param  array<string, scalar|null>  $metadata  never contains headers, bodies or full URLs
     * @param  bool  $slow  successful, but slower than the monitor's latency threshold (ADR-037)
     * @param  list<string>|null  $dnsAnswers  answers of a DNS monitor when a name server responded (ADR-042)
     */
    public function __construct(
        public bool $success,
        public ?int $durationMs,
        public ?int $statusCode,
        public ?ProbeErrorCode $errorCode,
        public array $metadata = [],
        public ?TlsCertificate $certificate = null,
        public bool $slow = false,
        public ?array $dnsAnswers = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function failure(ProbeErrorCode $errorCode, ?int $durationMs = null, ?int $statusCode = null, array $metadata = []): self
    {
        return new self(false, $durationMs, $statusCode, $errorCode, $metadata);
    }
}
