<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\DTOs\StatusPageData;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use Illuminate\Support\Facades\DB;

class SaveStatusPage
{
    public function __construct(
        private readonly SyncStatusPageComponents $syncStatusPageComponents,
        private readonly Quota $quota,
    ) {}

    public function handle(Organization $organization, StatusPageData $data, ?StatusPage $statusPage = null): StatusPage
    {
        $statusPage = DB::transaction(function () use ($organization, $data, $statusPage): StatusPage {
            $attributes = [
                'title' => $data->title,
                'slug' => $data->slug,
                'description' => $data->description,
                'is_published' => $data->isPublished,
                'show_uptime' => $data->showUptime,
            ];

            if ($statusPage === null) {
                $this->quota->ensureCanAdd($organization, Feature::StatusPages);

                $statusPage = $organization->statusPages()->create($attributes);
            } else {
                $statusPage->update($attributes);
            }

            $this->syncStatusPageComponents->handle($statusPage, $data->components);

            return $statusPage;
        });

        StatusPageSnapshot::forget($statusPage);

        return $statusPage;
    }
}
