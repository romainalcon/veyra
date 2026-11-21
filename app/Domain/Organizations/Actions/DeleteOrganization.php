<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Monitoring\Jobs\PurgeMonitorHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Events\OrganizationDeleting;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

class DeleteOrganization
{
    /**
     * Memberships, invitations and monitors are removed by foreign-key
     * cascades; the high-volume monitoring history is purged asynchronously
     * in bounded batches once the deletion is committed. Safe to call inside
     * a larger transaction (account deletion).
     */
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            OrganizationDeleting::dispatch($organization);

            /** @var list<string> $monitorIds */
            $monitorIds = Monitor::withTrashed()
                ->where('organization_id', $organization->id)
                ->pluck('id')
                ->all();

            $organization->delete();

            DB::afterCommit(function () use ($monitorIds): void {
                foreach (array_chunk($monitorIds, 500) as $chunk) {
                    PurgeMonitorHistory::dispatch($chunk);
                }
            });
        });
    }
}
