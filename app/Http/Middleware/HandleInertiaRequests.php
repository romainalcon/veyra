<?php

namespace App\Http\Middleware;

use App\Domain\Entitlements\Services\Quota;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\System\MonitoringStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * Only explicitly selected, non-sensitive attributes are shared.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => fn (): string => app()->getLocale(),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'email_verified_at']),
            ],
            'organizations' => fn (): array => $user === null ? [] : $user->organizations()
                ->orderBy('name')
                ->get(['organizations.id', 'organizations.name', 'organizations.slug'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ])
                ->all(),
            'currentOrganization' => fn (): ?array => $this->currentOrganization($request, $user),
            'system' => fn (): array => [
                'version' => (string) config('veyra.version'),
                'sourceUrl' => config('veyra.source_url'),
                'monitoring' => $user === null ? ['paused' => false, 'reasons' => []] : app(MonitoringStatus::class)->current(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string, role: string, openIncidents: int, can: array<string, bool>, limits: array<string, array{used: int, limit: int}>}|null
     */
    private function currentOrganization(Request $request, ?User $user): ?array
    {
        $organization = $request->attributes->get('currentOrganization');

        if (! $organization instanceof Organization || $user === null) {
            return null;
        }

        /** @var OrganizationMember $membership */
        $membership = $organization->getRelation('viewerMembership');

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'role' => $membership->role->value,
            'openIncidents' => $organization->incidents()->where('status', IncidentStatus::Open)->count(),
            'can' => [
                'update' => $user->can('update', $organization),
                'delete' => $user->can('delete', $organization),
                'manageMembers' => $user->can('manageMembers', $organization),
            ],
            // Only limited features are listed: empty on the Community edition (ADR-029).
            'limits' => app(Quota::class)->usage($organization),
        ];
    }
}
