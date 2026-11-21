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
     */
    public function __construct(
        public bool $success,
        public ?int $durationMs,
        public ?int $statusCode,
        public ?ProbeErrorCode $errorCode,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function failure(ProbeErrorCode $errorCode, ?int $durationMs = null, ?int $statusCode = null, array $metadata = []): self
    {
        return new self(false, $durationMs, $statusCode, $errorCode, $metadata);
    }
}
