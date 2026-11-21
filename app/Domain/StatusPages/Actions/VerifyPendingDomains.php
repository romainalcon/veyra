<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\StatusPages\Models\StatusPage;
use Carbon\CarbonImmutable;

/**
 * Scheduled (hourly): pending domains are retried for about a week; verified
 * domains are re-checked once a day.
 */
class VerifyPendingDomains
{
    public const PENDING_MAX_ATTEMPTS = 168;

    public const BATCH = 200;

    public function __construct(private readonly VerifyStatusPageDomain $verifyStatusPageDomain) {}

    /**
     * @return array{checked: int, verified: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $pages = StatusPage::query()
            ->whereNotNull('custom_domain')
            ->where(fn ($query) => $query
                ->where(fn ($pending) => $pending
                    ->whereNull('domain_verified_at')
                    ->where('domain_failures', '<', self::PENDING_MAX_ATTEMPTS))
                ->orWhere(fn ($verified) => $verified
                    ->whereNotNull('domain_verified_at')
                    ->where(fn ($due) => $due
                        ->whereNull('domain_checked_at')
                        ->orWhere('domain_checked_at', '<=', $now->subHours(23)))))
            ->orderBy('domain_checked_at')
            ->limit(self::BATCH)
            ->get();

        $verified = 0;

        foreach ($pages as $page) {
            $verified += $this->verifyStatusPageDomain->handle($page, $now) ? 1 : 0;
        }

        return ['checked' => $pages->count(), 'verified' => $verified];
    }
}
