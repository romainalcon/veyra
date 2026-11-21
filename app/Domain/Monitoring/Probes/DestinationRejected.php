<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use RuntimeException;

/**
 * Raised when a URL or one of its resolved addresses may not be probed.
 * Messages are safe to show to the monitor owner (no IPs of other hosts).
 */
class DestinationRejected extends RuntimeException
{
    public function __construct(public readonly ProbeErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
