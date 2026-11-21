<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\StatusPages\Contracts\TxtResolver;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\CustomDomainRegistry;
use App\Domain\StatusPages\Support\DomainName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks the `_veyra-challenge.<domain>` TXT record. A verified domain that
 * loses its record for REVOKE_AFTER_FAILURES consecutive checks is revoked,
 * so a domain handed over to someone else stops serving the page.
 */
class VerifyStatusPageDomain
{
    public const REVOKE_AFTER_FAILURES = 3;

    public function __construct(private readonly TxtResolver $txtResolver) {}

    public function handle(StatusPage $statusPage, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $domain = $statusPage->custom_domain;
        $token = $statusPage->domain_verification_token;

        if ($domain === null || $token === null) {
            return false;
        }

        $found = in_array(DomainName::challengeValue($token), array_map('trim', $this->txtResolver->lookup(DomainName::challengeName($domain))), true);

        DB::transaction(function () use ($statusPage, $domain, $found, $now): void {
            $locked = StatusPage::query()->lockForUpdate()->find($statusPage->id);

            if ($locked === null || $locked->custom_domain !== $domain) {
                return;
            }

            if ($found) {
                $locked->update([
                    'domain_verified_at' => $locked->domain_verified_at ?? $now,
                    'domain_checked_at' => $now,
                    'domain_failures' => 0,
                ]);

                return;
            }

            $failures = $locked->domain_failures + 1;
            $revoke = $locked->domain_verified_at !== null && $failures >= self::REVOKE_AFTER_FAILURES;

            $locked->update([
                'domain_checked_at' => $now,
                'domain_failures' => $revoke ? 0 : min(255, $failures),
                ...($revoke ? ['domain_verified_at' => null] : []),
            ]);
        });

        CustomDomainRegistry::forget($domain);
        $statusPage->refresh();

        return $found;
    }
}
