<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Actions\SetTwoFactorRequirement;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateTwoFactorRequirementRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TwoFactorRequirementController extends Controller
{
    public function update(UpdateTwoFactorRequirementRequest $request, Organization $organization, SetTwoFactorRequirement $setTwoFactorRequirement): RedirectResponse
    {
        Gate::authorize('requireTwoFactor', $organization);

        $setTwoFactorRequirement->handle($organization, $request->boolean('require_two_factor'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $organization->require_two_factor
            ? __('Two-factor authentication is now required for every member.')
            : __('Two-factor authentication is no longer required.')]);

        return to_route('organizations.edit', $organization);
    }
}
