<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Actions\SaveStatusPage;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StatusPagePresenter;
use App\Http\Requests\StatusPages\SaveStatusPageRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StatusPageController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAny', [StatusPage::class, $organization]);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('status-pages/Index', [
            'statusPages' => $organization->statusPages()
                ->withCount('components')
                ->orderBy('title')
                ->get()
                ->map(fn (StatusPage $statusPage): array => StatusPagePresenter::summary($statusPage))
                ->all(),
            'canManage' => $user->can('create', [StatusPage::class, $organization]),
        ]);
    }

    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [StatusPage::class, $organization]);

        return Inertia::render('status-pages/Create', $this->formOptions($organization));
    }

    public function store(SaveStatusPageRequest $request, Organization $organization, SaveStatusPage $saveStatusPage): RedirectResponse
    {
        Gate::authorize('create', [StatusPage::class, $organization]);

        $statusPage = $saveStatusPage->handle($organization, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status page created.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }

    public function edit(Organization $organization, StatusPage $statusPage): Response
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $statusPage->load('components');

        return Inertia::render('status-pages/Edit', [
            ...$this->formOptions($organization),
            'statusPage' => StatusPagePresenter::detail($statusPage),
        ]);
    }

    public function update(SaveStatusPageRequest $request, Organization $organization, StatusPage $statusPage, SaveStatusPage $saveStatusPage): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $saveStatusPage->handle($organization, $request->payload(), $statusPage);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status page saved.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }

    public function destroy(Organization $organization, StatusPage $statusPage): RedirectResponse
    {
        Gate::authorize('delete', [$statusPage, $organization]);

        StatusPageSnapshot::forget($statusPage);
        $statusPage->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status page deleted.')]);

        return to_route('organizations.status-pages.index', $organization);
    }

    /**
     * @return array{monitors: list<array{id: string, name: string}>, maxComponents: int}
     */
    private function formOptions(Organization $organization): array
    {
        return [
            'monitors' => array_values($organization->monitors()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Monitor $monitor): array => ['id' => $monitor->id, 'name' => $monitor->name])
                ->all()),
            'maxComponents' => StatusPageSnapshot::MAX_COMPONENTS,
        ];
    }
}
