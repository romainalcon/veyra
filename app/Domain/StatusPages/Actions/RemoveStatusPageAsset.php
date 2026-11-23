<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Removing branding is always allowed, whatever the entitlements.
 */
class RemoveStatusPageAsset
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StatusPage $statusPage, AssetKind $kind): void
    {
        DB::transaction(function () use ($statusPage, $kind): void {
            if ($statusPage->assets()->where('kind', $kind)->delete() > 0) {
                $this->audit->record($statusPage->organization_id, AuditAction::StatusPageAssetRemoved, AuditSnapshot::statusPageSubject($statusPage), metadata: ['kind' => $kind->value]);
            }
        });

        StatusPageSnapshot::forget($statusPage);
    }
}
