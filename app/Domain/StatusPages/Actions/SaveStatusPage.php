<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
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
        private readonly AuditLogger $audit,
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
                'accent_color' => $data->accentColor === null ? null : strtolower($data->accentColor),
                'subscriptions_enabled' => $data->subscriptionsEnabled,
            ];

            // Branding entitlement (ADR-029 "growth only"): an existing accent is kept, a new one needs the feature.
            if ($attributes['accent_color'] !== null && $attributes['accent_color'] !== $statusPage?->accent_color && ! $this->quota->allowsBranding($organization)) {
                throw new LimitReached(Feature::StatusPageBranding, 0);
            }

            $before = $statusPage === null ? null : AuditSnapshot::statusPage($statusPage);

            if ($statusPage === null) {
                $this->quota->ensureCanAdd($organization, Feature::StatusPages);

                $statusPage = $organization->statusPages()->create($attributes);
            } else {
                $statusPage->update($attributes);
            }

            $this->syncStatusPageComponents->handle($statusPage, $data->components);

            $this->audit->record(
                $organization,
                $before === null ? AuditAction::StatusPageCreated : AuditAction::StatusPageUpdated,
                AuditSnapshot::statusPageSubject($statusPage),
                $before,
                AuditSnapshot::statusPage($statusPage),
            );

            return $statusPage;
        });

        StatusPageSnapshot::forget($statusPage);

        return $statusPage;
    }
}
