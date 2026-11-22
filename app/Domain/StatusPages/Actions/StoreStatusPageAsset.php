<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAsset;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Domain\StatusPages\Support\ImageInspector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stores (or replaces) the logo or favicon of a status page. The bytes must
 * already be validated by ImageInspector; this re-checks them defensively.
 */
class StoreStatusPageAsset
{
    public function __construct(private readonly Quota $quota) {}

    /**
     * @throws LimitReached when the organization may not brand its pages
     */
    public function handle(StatusPage $statusPage, AssetKind $kind, string $bytes): StatusPageAsset
    {
        $image = ImageInspector::inspect($bytes, $kind);

        if ($image === null || strlen($bytes) > $kind->maxBytes()) {
            throw new InvalidArgumentException('Unsupported image.');
        }

        $asset = DB::transaction(function () use ($statusPage, $kind, $bytes, $image): StatusPageAsset {
            if (! $this->quota->allowsBranding($statusPage->organization)) {
                throw new LimitReached(Feature::StatusPageBranding, 0);
            }

            StatusPage::query()->whereKey($statusPage->id)->lockForUpdate()->value('id');

            return StatusPageAsset::query()->updateOrCreate(
                ['status_page_id' => $statusPage->id, 'kind' => $kind],
                [
                    'mime' => $image['mime'],
                    'sha256' => hash('sha256', $bytes),
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'size' => strlen($bytes),
                    'content' => $bytes,
                ],
            );
        });

        StatusPageSnapshot::forget($statusPage);

        return $asset;
    }
}
