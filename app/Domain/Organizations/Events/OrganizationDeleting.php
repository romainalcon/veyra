<?php

namespace App\Domain\Organizations\Events;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched synchronously inside the deletion transaction, while the
 * organization and its rows still exist (ADR-030). Listeners may read what
 * they need to clean up outside the database (e.g. cancel a subscription
 * with a job dispatched after commit); throwing aborts the deletion.
 */
class OrganizationDeleting
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization) {}
}
