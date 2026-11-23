<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Organizations\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;

class RevokeInvitation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(OrganizationInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $this->audit->record($invitation->organization_id, AuditAction::InvitationRevoked, AuditSnapshot::invitationSubject($invitation), before: ['email' => $invitation->email, 'role' => $invitation->role]);

            $invitation->delete();
        });
    }
}
