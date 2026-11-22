<?php

namespace App\Domain\Incidents\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A public update was posted on an incident (status page subscribers).
 */
class PublicIncidentUpdatePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $incidentEventId) {}
}
