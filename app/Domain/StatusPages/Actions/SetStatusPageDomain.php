<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\CustomDomainRegistry;
use App\Domain\StatusPages\Support\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets (or removes, with null) the custom domain of a status page. A new
 * domain always starts unverified with a fresh ownership token. Adding a
 * domain to a page without one counts against the custom domain limit;
 * replacing or removing one never does.
 */
class SetStatusPageDomain
{
    public function __construct(private readonly Quota $quota) {}

    public function handle(StatusPage $statusPage, ?string $domain): StatusPage
    {
        $domain = $domain === null ? null : DomainName::normalize($domain);
        $previous = $statusPage->custom_domain;

        if ($domain === $previous) {
            return $statusPage;
        }

        DB::transaction(function () use ($statusPage, $domain, $previous): void {
            if ($previous === null && $domain !== null) {
                $this->quota->ensureCanAdd($statusPage->organization, Feature::CustomDomains);
            }

            $statusPage->update([
                'custom_domain' => $domain,
                'domain_verification_token' => $domain === null ? null : Str::random(40),
                'domain_verified_at' => null,
                'domain_checked_at' => null,
                'domain_failures' => 0,
            ]);
        });

        CustomDomainRegistry::forget($previous);
        CustomDomainRegistry::forget($domain);

        return $statusPage;
    }
}
