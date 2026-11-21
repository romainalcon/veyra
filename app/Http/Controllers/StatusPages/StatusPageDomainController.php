<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Actions\SetStatusPageDomain;
use App\Domain\StatusPages\Actions\VerifyStatusPageDomain;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Support\DomainName;
use App\Http\Controllers\Controller;
use App\Rules\CustomDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StatusPageDomainController extends Controller
{
    public function update(Request $request, Organization $organization, StatusPage $statusPage, SetStatusPageDomain $setStatusPageDomain): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $request->merge(['custom_domain' => DomainName::normalize((string) $request->input('custom_domain')) ?? $request->input('custom_domain')]);

        $validated = $request->validate([
            'custom_domain' => [
                'required',
                'string',
                'max:253',
                new CustomDomain,
                Rule::unique('status_pages', 'custom_domain')->ignore($statusPage->id),
            ],
        ]);

        $setStatusPageDomain->handle($statusPage, (string) $validated['custom_domain']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain saved. Add the TXT record, then verify it.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }

    public function destroy(Organization $organization, StatusPage $statusPage, SetStatusPageDomain $setStatusPageDomain): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $setStatusPageDomain->handle($statusPage, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom domain removed.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }

    public function verify(Organization $organization, StatusPage $statusPage, VerifyStatusPageDomain $verifyStatusPageDomain): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $verified = $verifyStatusPageDomain->handle($statusPage);

        Inertia::flash('toast', $verified
            ? ['type' => 'success', 'message' => __('Domain verified.')]
            : ['type' => 'error', 'message' => __('The TXT record was not found yet. DNS changes can take a while to propagate.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }
}
