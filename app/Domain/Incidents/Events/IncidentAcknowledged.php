<?php

namespace App\Domain\Incidents\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentAcknowledged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $incidentId,
        /** Null when an API token acknowledged it (ADR-044). */
        public readonly ?string $userId,
    ) {}
}
