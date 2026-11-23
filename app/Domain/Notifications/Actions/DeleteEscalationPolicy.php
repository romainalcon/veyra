<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Notifications\Models\EscalationPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Monitors using the policy fall back to the organization default
 * (`monitors.escalation_policy_id` is nulled by the foreign key).
 */
class DeleteEscalationPolicy
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EscalationPolicy $policy): void
    {
        DB::transaction(function () use ($policy): void {
            $this->audit->record($policy->organization_id, AuditAction::EscalationPolicyDeleted, AuditSnapshot::escalationPolicySubject($policy), before: AuditSnapshot::escalationPolicy($policy));

            $policy->delete();
        });
    }
}
