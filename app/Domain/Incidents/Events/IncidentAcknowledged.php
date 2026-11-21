<?php

namespace App\Domain\Incidents\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentAcknowledged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $incidentId,
        public readonly string $userId,
    ) {}
}
