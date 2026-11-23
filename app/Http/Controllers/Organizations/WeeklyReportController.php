<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateWeeklyReportRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The signed-in member's own weekly report preference; nobody changes
 * another member's.
 */
class WeeklyReportController extends Controller
{
    public function update(UpdateWeeklyReportRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('view', $organization);

        /** @var User $user */
        $user = $request->user();
        $enabled = $request->boolean('weekly_report');

        OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->update(['weekly_report' => $enabled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $enabled
            ? __('You will receive the weekly report every Monday.')
            : __('You will no longer receive the weekly report.')]);

        return to_route('organizations.edit', $organization);
    }
}
