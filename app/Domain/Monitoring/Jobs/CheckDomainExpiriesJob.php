<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Domains\CheckDomainExpiry;
use App\Domain\Monitoring\Domains\RefreshDomainExpiries;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes domain registrations through RDAP, then raises expiry warnings
 * (ADR-043). Network lookups run on the `maintenance` queue, not in the
 * scheduler process; one run at a time, bounded by a time budget below the
 * job timeout. Idempotent: alerts and deliveries have unique keys.
 */
class CheckDomainExpiriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(RefreshDomainExpiries $refresh, CheckDomainExpiry $check): void
    {
        if (! (bool) config('veyra.domains.enabled', true)) {
            return;
        }

        $refresh->handle(budgetSeconds: 240);
        $check->handle();
    }
}
