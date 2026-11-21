<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Monitoring\Jobs\PurgeMonitorHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;

class DeleteOrganization
{
    /**
     * Memberships, invitations and monitors are removed by foreign-key
     * cascades; the high-volume monitoring history is purged asynchronously
     * in bounded batches.
     */
    public function handle(Organization $organization): void
    {
        /** @var list<string> $monitorIds */
        $monitorIds = Monitor::withTrashed()
            ->where('organization_id', $organization->id)
            ->pluck('id')
            ->all();

        $organization->delete();

        foreach (array_chunk($monitorIds, 500) as $chunk) {
            PurgeMonitorHistory::dispatch($chunk);
        }
    }
}
