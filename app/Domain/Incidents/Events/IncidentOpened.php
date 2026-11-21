<?php

namespace App\Domain\Incidents\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event (hook point for notifications in Sprint 4).
 */
class IncidentOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $incidentId) {}
}
