<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Organizations\Models\ApiToken;
use Illuminate\Support\Facades\DB;

class RevokeApiToken
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Revocation is immediate and permanent; the row is kept so that incident
     * timelines can still name the token (ADR-044). Idempotent.
     */
    public function handle(ApiToken $token): void
    {
        DB::transaction(function () use ($token): void {
            $revoked = ApiToken::query()
                ->whereKey($token->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($revoked > 0) {
                $this->audit->record($token->organization_id, AuditAction::ApiTokenRevoked, AuditSnapshot::apiTokenSubject($token), before: AuditSnapshot::apiToken($token));
            }
        });
    }
}
