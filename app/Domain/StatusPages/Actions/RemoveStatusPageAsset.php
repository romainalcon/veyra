<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;

/**
 * Removing branding is always allowed, whatever the entitlements.
 */
class RemoveStatusPageAsset
{
    public function handle(StatusPage $statusPage, AssetKind $kind): void
    {
        $statusPage->assets()->where('kind', $kind)->delete();

        StatusPageSnapshot::forget($statusPage);
    }
}
