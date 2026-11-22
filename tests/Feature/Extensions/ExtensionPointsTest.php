<?php

use App\Domain\Extensions\OrganizationNavigation;
use App\Domain\Extensions\PublicNavigation;
use App\Domain\Extensions\UserNavigation;
use App\Domain\Identity\Contracts\Impersonation;
use App\Domain\Monitoring\Actions\RaiseMonitorIntervals;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Jobs\PurgeMonitorHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Events\OrganizationDeleting;
use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Models\StatusPage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, OrganizationRole::Owner, ['name' => 'Acme']);
});

describe('organization navigation', function () {
    beforeEach(function () {
        Route::middleware('web')->get('o/{organization:slug}/extension-test', fn () => 'ok')->name('extension-test.show');
        Route::getRoutes()->refreshNameLookups();
        Gate::define('viewExtensionTest', fn (User $user, Organization $organization): bool => $user->roleIn($organization) === OrganizationRole::Owner);
    });

    test('is empty on the Community edition', function () {
        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.navigation', []));
    });

    test('shows registered entries, translated, to users allowed to see them', function () {
        app(OrganizationNavigation::class)->add('billing', 'Settings', 'extension-test.show', 'viewExtensionTest', 'credit-card');
        $this->organization->update(['locale' => 'fr']);
        $viewer = memberOf($this->organization, OrganizationRole::Viewer);

        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.navigation', [[
                'id' => 'billing',
                'title' => 'Réglages',
                'href' => route('extension-test.show', $this->organization),
                'icon' => 'credit-card',
            ]]));

        $this->actingAs($viewer)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.navigation', []));
    });

    test('refuses icons the interface cannot render', function () {
        expect(fn () => app(OrganizationNavigation::class)->add('x', 'X', 'extension-test.show', icon: 'rocket'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('user navigation', function () {
    beforeEach(function () {
        Route::middleware('web')->get('extension-admin-test', fn () => 'ok')->name('extension-admin-test.index');
        Route::getRoutes()->refreshNameLookups();
        config(['veyra.admin_emails' => ['Operator@Example.com']]);
        $this->operator = User::factory()->create(['email' => 'operator@example.com']);
    });

    test('is empty on the Community edition, even for instance administrators', function () {
        $this->actingAs($this->operator)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.navigation', []));
    });

    test('shows registered entries, translated, to users allowed to see them on every page', function () {
        app(UserNavigation::class)->add('admin', 'Settings', 'extension-admin-test.index', 'administerInstance', 'shield');
        $entry = [
            'id' => 'admin',
            'title' => 'Réglages',
            'href' => route('extension-admin-test.index'),
            'icon' => 'shield',
        ];

        $this->actingAs($this->operator)
            ->withHeader('Accept-Language', 'fr')
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.navigation', [$entry]));

        $this->organization->members()->create(['user_id' => $this->operator->id, 'role' => OrganizationRole::Viewer, 'joined_at' => now()]);
        $this->organization->update(['locale' => 'fr']);

        $this->actingAs($this->operator)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('auth.navigation', [$entry]));

        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('auth.navigation', []));
    });

    test('entries without an ability are shown to every signed-in user', function () {
        app(UserNavigation::class)->add('help', 'Settings', 'extension-admin-test.index');

        $this->actingAs($this->owner)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.navigation.0.id', 'help'));
    });

    test('refuses icons the interface cannot render', function () {
        expect(fn () => app(UserNavigation::class)->add('x', 'X', 'extension-admin-test.index', icon: 'rocket'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('impersonation', function () {
    test('is never in progress on the Community edition', function () {
        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('auth.impersonation', null));
    });

    test('shares the impersonation an extension reports on every authenticated page', function () {
        app()->bind(Impersonation::class, fn (): Impersonation => new class implements Impersonation
        {
            public function current(Request $request): ?array
            {
                return ['operator' => 'operator@example.com', 'stopUrl' => 'https://veyra.test/admin/impersonation/stop'];
            }
        });
        $impersonation = ['operator' => 'operator@example.com', 'stopUrl' => 'https://veyra.test/admin/impersonation/stop'];

        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('auth.impersonation', $impersonation));

        $this->actingAs($this->owner)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.impersonation', $impersonation));
    });
});

describe('public navigation', function () {
    beforeEach(function () {
        Route::middleware('web')->get('extension-pricing-test', fn () => 'ok')->name('extension-pricing-test');
        Route::middleware('web')->get('extension-terms-test', fn () => 'ok')->name('extension-terms-test');
        Route::getRoutes()->refreshNameLookups();
        config(['veyra.landing_page' => true]);
    });

    test('is empty on the Community edition', function () {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('publicNavigation', []));
    });

    test('shares registered links, translated, with guests and signed-in users', function () {
        app(PublicNavigation::class)->add('pricing', 'Features', 'extension-pricing-test', 'header');
        app(PublicNavigation::class)->add('terms', 'Source code', 'extension-terms-test');
        $links = [
            ['id' => 'pricing', 'title' => 'Fonctionnalités', 'href' => route('extension-pricing-test'), 'placement' => 'header'],
            ['id' => 'terms', 'title' => 'Code source', 'href' => route('extension-terms-test'), 'placement' => 'footer'],
        ];

        $this->withHeader('Accept-Language', 'fr')
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('publicNavigation', $links));

        $this->withHeader('Accept-Language', 'fr')
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('publicNavigation', $links));

        $this->organization->update(['locale' => 'fr']);

        $this->actingAs($this->owner)
            ->get(route('organizations.dashboard', $this->organization))
            ->assertInertia(fn (Assert $page) => $page->where('publicNavigation', $links));
    });

    test('the same id may be used in both placements', function () {
        app(PublicNavigation::class)->add('pricing', 'Features', 'extension-pricing-test', 'header');
        app(PublicNavigation::class)->add('pricing', 'Features', 'extension-pricing-test', 'footer');

        expect(app(PublicNavigation::class)->all())->toHaveCount(2);
    });

    test('is never shared with public status pages', function () {
        app(PublicNavigation::class)->add('terms', 'Source code', 'extension-terms-test');
        StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);

        $this->get(route('status-pages.public', 'acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('publicNavigation'));
    });

    test('refuses unknown placements', function () {
        expect(fn () => app(PublicNavigation::class)->add('x', 'X', 'extension-terms-test', 'sidebar'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('organization deletion', function () {
    test('listeners run before the rows disappear', function () {
        $monitor = Monitor::factory()->for($this->organization)->create();
        $seen = null;
        Event::listen(OrganizationDeleting::class, function (OrganizationDeleting $event) use (&$seen): void {
            $seen = $event->organization->monitors()->pluck('id')->all();
        });

        $this->actingAs($this->owner)
            ->delete(route('organizations.destroy', $this->organization), ['confirmation' => $this->organization->slug])
            ->assertRedirect(route('dashboard'));

        expect($seen)->toBe([$monitor->id])
            ->and(Organization::find($this->organization->id))->toBeNull();
    });

    test('a failing listener aborts the deletion', function () {
        Event::listen(OrganizationDeleting::class, fn () => throw new RuntimeException('Subscription still active.'));

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->owner)
            ->delete(route('organizations.destroy', $this->organization), ['confirmation' => $this->organization->slug]))
            ->toThrow(RuntimeException::class);

        expect(Organization::find($this->organization->id))->not->toBeNull();
    });

    test('deleting an account deletes its sole-member organizations through the same path', function () {
        Queue::fake([PurgeMonitorHistory::class]);
        Event::fake([OrganizationDeleting::class]);
        $monitor = Monitor::factory()->for($this->organization)->create();
        $shared = organizationWith($this->owner);
        memberOf($shared, OrganizationRole::Owner);

        $this->actingAs($this->owner)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(OrganizationDeleting::class, fn (OrganizationDeleting $event): bool => $event->organization->is($this->organization));
        Event::assertDispatchedTimes(OrganizationDeleting::class, 1);
        Queue::assertPushed(PurgeMonitorHistory::class, fn (PurgeMonitorHistory $job): bool => in_array($monitor->id, $job->monitorIds, true));

        expect(Organization::find($this->organization->id))->toBeNull()
            ->and(Organization::find($shared->id))->not->toBeNull();
    });

    test('the monitoring history of a deleted account is purged', function () {
        $monitor = Monitor::factory()->for($this->organization)->create();
        MonitorCheck::factory()->for($monitor)->count(3)->create();

        $this->actingAs($this->owner)->delete(route('profile.destroy'), ['password' => 'password']);

        expect(MonitorCheck::query()->where('monitor_id', $monitor->id)->count())->toBe(0);
    });
});

describe('raising monitor intervals', function () {
    test('raises probed monitors below the minimum, paused ones included', function () {
        $fast = Monitor::factory()->for($this->organization)->create(['interval_seconds' => 60]);
        $pausedTcp = Monitor::factory()->for($this->organization)->tcp()->paused()->create(['interval_seconds' => 120]);
        $slow = Monitor::factory()->for($this->organization)->create(['interval_seconds' => 600]);
        $heartbeat = Monitor::factory()->for($this->organization)->heartbeat('token-1', periodSeconds: 60)->create();
        $foreign = Monitor::factory()->create(['interval_seconds' => 60]);
        $nextCheck = $fast->next_check_at;

        expect(app(RaiseMonitorIntervals::class)->handle($this->organization, 300))->toBe(2);

        expect($fast->fresh()->interval_seconds)->toBe(300)
            ->and($fast->fresh()->next_check_at->equalTo($nextCheck))->toBeTrue()
            ->and($pausedTcp->fresh()->interval_seconds)->toBe(300)
            ->and($pausedTcp->fresh()->type)->toBe(MonitorType::Tcp)
            ->and($slow->fresh()->interval_seconds)->toBe(600)
            ->and($heartbeat->fresh()->interval_seconds)->toBe(60)
            ->and($foreign->fresh()->interval_seconds)->toBe(60);
    });

    test('uses the shortest selectable interval at or above the minimum', function (int $minimum, int $expected) {
        expect(RaiseMonitorIntervals::intervalFor($minimum))->toBe($expected);
    })->with([[60, 60], [240, 300], [300, 300], [3601, 3600]]);
});

test('renders an extension page without preloading it from the core manifest', function () {
    Route::middleware('web')->get('extension-page-test', fn () => Inertia\Inertia::render('cloud/billing/Show'));

    $response = $this->get('extension-page-test')->assertOk();

    expect($response->getContent())->not->toContain('resources/js/pages/cloud/billing/Show.vue');
});
