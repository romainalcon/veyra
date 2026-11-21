<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Actions\CreateOrganization;
use App\Domain\Organizations\Actions\DeleteOrganization;
use App\Domain\Organizations\Actions\UpdateOrganization;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\SaveOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('organizations/Create');
    }

    public function store(SaveOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organization = $createOrganization->handle($user, $request->toData());

        return to_route('organizations.dashboard', $organization);
    }

    public function dashboard(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Dashboard', [
            'monitorCounts' => [
                'total' => $organization->monitors()->count(),
                'paused' => $organization->monitors()->where('enabled', false)->count(),
            ],
        ]);
    }

    public function edit(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Settings', [
            'organization' => $organization->only(['id', 'name', 'slug']),
        ]);
    }

    public function update(SaveOrganizationRequest $request, Organization $organization, UpdateOrganization $updateOrganization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $updateOrganization->handle($organization, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('organizations.edit', $organization);
    }

    public function destroy(Request $request, Organization $organization, DeleteOrganization $deleteOrganization): RedirectResponse
    {
        Gate::authorize('delete', $organization);

        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$organization->slug],
        ]);

        $deleteOrganization->handle($organization);

        return to_route('dashboard');
    }
}
