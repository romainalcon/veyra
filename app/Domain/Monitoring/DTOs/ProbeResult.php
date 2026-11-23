<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\FailureEvidence;

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
     * @param  CheckTimings|null  $timings  phase breakdown (ADR-050)
     * @param  FailureEvidence|null  $evidence  what the server answered on a failing HTTP check (ADR-050), never in metadata
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
        public ?CheckTimings $timings = null,
        public ?FailureEvidence $evidence = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function failure(ProbeErrorCode $errorCode, ?int $durationMs = null, ?int $statusCode = null, array $metadata = [], ?CheckTimings $timings = null, ?FailureEvidence $evidence = null): self
    {
        return new self(false, $durationMs, $statusCode, $errorCode, $metadata, timings: $timings, evidence: $evidence);
    }
}
