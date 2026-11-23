<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\DTOs\EscalationPolicyData;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Creates or replaces an escalation policy and its steps (ADR-045). At most
 * one policy per organization is the default: the organization row is
 * locked so that two concurrent saves cannot both keep `is_default`.
 * Replacing steps never touches running incidents beyond the positions they
 * already reached (`incidents.escalation_step`).
 */
class SaveEscalationPolicy
{
    public function handle(Organization $organization, EscalationPolicyData $data, ?EscalationPolicy $policy = null): EscalationPolicy
    {
        return DB::transaction(function () use ($organization, $data, $policy): EscalationPolicy {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            if ($data->isDefault) {
                EscalationPolicy::query()
                    ->where('organization_id', $organization->id)
                    ->when($policy !== null, fn ($query) => $query->whereKeyNot($policy->id))
                    ->update(['is_default' => false]);
            }

            $attributes = ['name' => $data->name, 'is_default' => $data->isDefault];

            if ($policy === null) {
                $policy = $organization->escalationPolicies()->create($attributes);
            } else {
                $policy->update($attributes);
                $policy->steps()->delete();
            }

            foreach ($data->steps as $index => $step) {
                $model = $policy->steps()->create(['position' => $index + 1, 'delay_minutes' => $step['delay_minutes']]);

                // Defence in depth: only channels of the policy's own organization.
                $model->channels()->sync(
                    NotificationChannel::query()
                        ->where('organization_id', $organization->id)
                        ->whereIn('id', $step['channel_ids'])
                        ->pluck('id')
                        ->all(),
                );
            }

            return $policy;
        });
    }
}
