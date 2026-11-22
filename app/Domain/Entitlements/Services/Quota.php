<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enforces organization limits. Limits apply to growth only: resources above
 * a lowered limit are kept, but nothing more can be added (ADR-029).
 */
class Quota
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function limit(Organization $organization, Feature $feature): ?int
    {
        return $this->entitlements->limit($organization, $feature);
    }

    /**
     * Ensure one more resource of a counted feature fits. Must run inside the
     * transaction that adds it: the organization row is locked, so concurrent
     * additions are serialized and counted one after the other.
     *
     * @param  (Closure(): int)|null  $used  current usage when it differs from the default count
     *
     * @throws LimitReached
     */
    public function ensureCanAdd(Organization $organization, Feature $feature, ?Closure $used = null): void
    {
        if (! $feature->isCounted()) {
            throw new LogicException("{$feature->value} is not a counted feature.");
        }

        $limit = $this->limit($organization, $feature);

        if ($limit === null) {
            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Limits must be checked inside the transaction that adds the resource.');
        }

        Organization::query()->whereKey($organization->id)->lockForUpdate()->value('id');

        $current = $used !== null ? $used() : $this->used($organization, $feature);

        if ($current >= $limit) {
            throw new LimitReached($feature, $limit);
        }
    }

    public function used(Organization $organization, Feature $feature): int
    {
        return match ($feature) {
            Feature::Monitors => $organization->monitors()->where('enabled', true)->count(),
            Feature::Members => $organization->members()->count() + $organization->invitations()->pending()->count(),
            Feature::StatusPages => $organization->statusPages()->count(),
            Feature::CustomDomains => $organization->statusPages()->whereNotNull('custom_domain')->count(),
            Feature::NotificationChannels => $organization->notificationChannels()->count(),
            Feature::StatusPageSubscribers => StatusPageSubscriber::query()->where('organization_id', $organization->id)->count(),
            Feature::MinIntervalSeconds, Feature::CheckRetentionDays, Feature::StatusPageBranding => throw new LogicException("{$feature->value} is not a counted feature."),
        };
    }

    /**
     * Shortest interval allowed for probed monitors; null when unrestricted.
     */
    public function minIntervalSeconds(Organization $organization): ?int
    {
        return $this->limit($organization, Feature::MinIntervalSeconds);
    }

    /**
     * Whether status pages may carry a logo, favicon and accent colour.
     */
    public function allowsBranding(Organization $organization): bool
    {
        return $this->limit($organization, Feature::StatusPageBranding) !== 0;
    }

    /**
     * Days of raw checks kept for this organization (instance default when unlimited).
     */
    public function checkRetentionDays(Organization $organization): int
    {
        return max(1, $this->limit($organization, Feature::CheckRetentionDays)
            ?? (int) config('veyra.monitoring.check_retention_days', 30));
    }

    /**
     * Usage of every limited counted feature, for the interface. Unlimited
     * features are left out, so the Community edition shares an empty list.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public function usage(Organization $organization): array
    {
        $usage = [];

        foreach (Feature::counted() as $feature) {
            $limit = $this->limit($organization, $feature);

            if ($limit !== null) {
                $usage[$feature->value] = ['used' => $this->used($organization, $feature), 'limit' => $limit];
            }
        }

        return $usage;
    }
}
