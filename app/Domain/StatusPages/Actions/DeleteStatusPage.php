<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use Illuminate\Support\Facades\DB;

class DeleteStatusPage
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StatusPage $statusPage): void
    {
        DB::transaction(function () use ($statusPage): void {
            $this->audit->record($statusPage->organization_id, AuditAction::StatusPageDeleted, AuditSnapshot::statusPageSubject($statusPage), before: AuditSnapshot::statusPage($statusPage));

            $statusPage->delete();
        });

        StatusPageSnapshot::forget($statusPage);
    }
}
