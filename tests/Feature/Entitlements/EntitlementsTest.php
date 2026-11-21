<?php

use App\Domain\Entitlements\CommunityEntitlements;
use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Actions\SetMonitorEnabled;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\StatusPages\Models\StatusPage;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeEntitlements;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, OrganizationRole::Owner, ['name' => 'Acme']);
    $this->entitlements = new FakeEntitlements;
    $this->app->instance(Entitlements::class, $this->entitlements);
});

function limitedMonitorPayload(array $overrides = []): array
{
    return [
        'type' => 'http',
        'name' => 'Website',
        'url' => 'https://example.com/health',
        'method' => 'GET',
        'interval' => 300,
        'timeout' => 10,
        'expected_statuses' => '200-299',
        ...$overrides,
    ];
}

describe('community edition', function () {
    beforeEach(fn () => $this->app->instance(Entitlements::class, new CommunityEntitlements));

    test('is bound by default and limits nothing', function () {
        $this->app->forgetInstance(Entitlements::class);
        $this->app->bind(Entitlements::class, CommunityEntitlements::class);

        expect(app(Entitlements::class))->toBeInstanceOf(CommunityEntitlements::class);

        foreach (Feature::cases() as $feature) {
            expect(app(Quota::class)->limit($this->organization, $feature))->toBeNull();
        }

        expect(app(Quota::class)->checkRetentionDays($this->organization))->toBe((int) config('veyra.monitoring.check_retention_days'));
    });

    test('shares no limits and offers every interval', function () {
        $this->actingAs($this->owner)
            ->get(route('organizations.monitors.create', $this->organization))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentOrganization.limits', [])
                ->has('intervals', 7));
    });
});

describe('monitors', function () {
    test('creation is refused at the active monitor limit, with a translated message', function () {
        $this->entitlements->set(Feature::Monitors, 1);
        Monitor::factory()->for($this->organization)->create();

        $this->actingAs($this->owner)
            ->from(route('organizations.monitors.create', $this->organization))
            ->post(route('organizations.monitors.store', $this->organization), limitedMonitorPayload())
            ->assertRedirect(route('organizations.monitors.create', $this->organization))
            ->assertSessionHasErrors(['limit' => 'This organization is limited to 1 active monitors.']);

        expect($this->organization->monitors()->count())->toBe(1);
    });

    test('the limit message follows the organization language', function () {
        $this->organization->update(['locale' => 'fr']);
        $this->entitlements->set(Feature::Monitors, 0);

        $this->actingAs($this->owner)
            ->post(route('organizations.monitors.store', $this->organization), limitedMonitorPayload())
            ->assertSessionHasErrors(['limit' => 'Cette organisation est limitée à 0 moniteurs actifs.']);
    });

    test('paused monitors do not count', function () {
        $this->entitlements->set(Feature::Monitors, 1);
        Monitor::factory()->for($this->organization)->paused()->count(2)->create();

        $this->actingAs($this->owner)
            ->post(route('organizations.monitors.store', $this->organization), limitedMonitorPayload())
            ->assertSessionHasNoErrors();

        expect($this->organization->monitors()->where('enabled', true)->count())->toBe(1);
    });

    test('resuming is refused at the limit while pausing is always allowed', function () {
        $this->entitlements->set(Feature::Monitors, 1);
        $active = Monitor::factory()->for($this->organization)->create();
        $paused = Monitor::factory()->for($this->organization)->paused()->create();

        $this->actingAs($this->owner)
            ->patch(route('organizations.monitors.enabled', [$this->organization, $paused]), ['enabled' => true])
            ->assertSessionHasErrors('limit');
        expect($paused->fresh()->enabled)->toBeFalse();

        $this->patch(route('organizations.monitors.enabled', [$this->organization, $active]), ['enabled' => false])
            ->assertSessionHasNoErrors();
        $this->patch(route('organizations.monitors.enabled', [$this->organization, $paused]), ['enabled' => true])
            ->assertSessionHasNoErrors();

        expect($paused->fresh()->enabled)->toBeTrue()
            ->and($active->fresh()->enabled)->toBeFalse();
    });

    test('monitors above a lowered limit are kept', function () {
        Monitor::factory()->for($this->organization)->count(3)->create();
        $this->entitlements->set(Feature::Monitors, 1);

        expect(app(Quota::class)->usage($this->organization)['monitors'])->toBe(['used' => 3, 'limit' => 1])
            ->and($this->organization->monitors()->where('enabled', true)->count())->toBe(3);
    });

    test('a limit of one organization never affects another', function () {
        $other = organizationWith($this->owner);
        $this->entitlements->set(Feature::Monitors, 0, $this->organization);

        $this->actingAs($this->owner)
            ->post(route('organizations.monitors.store', $other), limitedMonitorPayload())
            ->assertSessionHasNoErrors();

        expect($other->monitors()->count())->toBe(1);
    });

    test('the shared usage lists only limited features', function () {
        $this->entitlements->set(Feature::Monitors, 5)->set(Feature::MinIntervalSeconds, 300);
        Monitor::factory()->for($this->organization)->count(2)->create();

        $this->actingAs($this->owner)
            ->get(route('organizations.monitors.index', $this->organization))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentOrganization.limits', ['monitors' => ['used' => 2, 'limit' => 5]]));
    });

});

describe('minimum interval', function () {
    beforeEach(fn () => $this->entitlements->set(Feature::MinIntervalSeconds, 300));

    test('shorter intervals are refused and hidden', function () {
        $this->actingAs($this->owner)
            ->post(route('organizations.monitors.store', $this->organization), limitedMonitorPayload(['interval' => 60]))
            ->assertSessionHasErrors(['interval' => 'The interval must be at least 300 seconds for this organization.']);

        $this->get(route('organizations.monitors.create', $this->organization))
            ->assertInertia(fn (Assert $page) => $page
                ->where('intervals.0.value', 300)
                ->has('intervals', 5));
    });

    test('heartbeat periods are not restricted', function () {
        $this->actingAs($this->owner)
            ->post(route('organizations.monitors.store', $this->organization), [
                'type' => 'heartbeat', 'name' => 'Nightly job', 'interval' => 60, 'grace_minutes' => 5,
            ])
            ->assertSessionHasNoErrors();
    });

    test('an edited monitor may keep an interval below a lowered minimum, not go lower', function () {
        $monitor = Monitor::factory()->for($this->organization)->create(['interval_seconds' => 120]);

        $update = fn (int $interval) => $this->actingAs($this->owner)->put(
            route('organizations.monitors.update', [$this->organization, $monitor]),
            limitedMonitorPayload(['interval' => $interval, 'headers_mode' => 'keep']),
        );

        $update(120)->assertSessionHasNoErrors();
        $update(60)->assertSessionHasErrors('interval');

        $this->get(route('organizations.monitors.edit', [$this->organization, $monitor]))
            ->assertInertia(fn (Assert $page) => $page->where('intervals.0.value', 120)->has('intervals', 6));
    });
});

describe('members', function () {
    test('pending invitations count against the member limit', function () {
        $this->entitlements->set(Feature::Members, 2);
        OrganizationInvitation::factory()->create(['organization_id' => $this->organization->id, 'email' => 'first@example.com']);

        $this->actingAs($this->owner)
            ->post(route('organizations.invitations.store', $this->organization), ['email' => 'second@example.com', 'role' => 'member'])
            ->assertSessionHasErrors('limit');

        expect($this->organization->invitations()->count())->toBe(1);
    });

    test('re-sending a pending invitation is not an addition', function () {
        $this->entitlements->set(Feature::Members, 2);
        OrganizationInvitation::factory()->create(['organization_id' => $this->organization->id, 'email' => 'first@example.com']);

        $this->actingAs($this->owner)
            ->post(route('organizations.invitations.store', $this->organization), ['email' => 'first@example.com', 'role' => 'admin'])
            ->assertSessionHasNoErrors();

        expect($this->organization->invitations()->sole()->role)->toBe(OrganizationRole::Admin);
    });

    test('accepting is refused when the limit was lowered since the invitation', function () {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        OrganizationInvitation::factory()->withToken('late')->create([
            'organization_id' => $this->organization->id,
            'email' => 'invitee@example.com',
        ]);
        $this->entitlements->set(Feature::Members, 1);

        $this->actingAs($invitee)
            ->post(route('invitations.accept', 'late'))
            ->assertSessionHasErrors(['invitation' => 'This organization is limited to 1 members, including pending invitations.']);

        expect($this->organization->members()->count())->toBe(1)
            ->and($this->organization->invitations()->count())->toBe(1);
    });

    test('accepting within the limit still works', function () {
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        OrganizationInvitation::factory()->withToken('ok')->create([
            'organization_id' => $this->organization->id,
            'email' => 'invitee@example.com',
        ]);
        $this->entitlements->set(Feature::Members, 2);

        $this->actingAs($invitee)
            ->post(route('invitations.accept', 'ok'))
            ->assertRedirect(route('organizations.dashboard', $this->organization));

        expect($this->organization->members()->count())->toBe(2);
    });
});

describe('status pages, domains and channels', function () {
    test('status page creation is limited, updates are not', function () {
        $this->entitlements->set(Feature::StatusPages, 1);
        $page = StatusPage::factory()->for($this->organization)->create(['slug' => 'acme']);

        $payload = ['title' => 'Acme status', 'slug' => 'acme-two', 'is_published' => '1', 'show_uptime' => '1'];

        $this->actingAs($this->owner)
            ->post(route('organizations.status-pages.store', $this->organization), $payload)
            ->assertSessionHasErrors('limit');

        $this->put(route('organizations.status-pages.update', [$this->organization, $page]), [...$payload, 'slug' => 'acme'])
            ->assertSessionHasNoErrors();

        expect($this->organization->statusPages()->count())->toBe(1);
    });

    test('adding a custom domain is limited, replacing and removing are not', function () {
        $this->entitlements->set(Feature::CustomDomains, 1);
        $withDomain = StatusPage::factory()->for($this->organization)->create(['slug' => 'one', 'custom_domain' => 'status.acme-corp.com']);
        $withoutDomain = StatusPage::factory()->for($this->organization)->create(['slug' => 'two']);

        $this->actingAs($this->owner)
            ->put(route('organizations.status-pages.domain.update', [$this->organization, $withoutDomain]), ['custom_domain' => 'status2.acme-corp.com'])
            ->assertSessionHasErrors('limit');
        expect($withoutDomain->fresh()->custom_domain)->toBeNull();

        $this->put(route('organizations.status-pages.domain.update', [$this->organization, $withDomain]), ['custom_domain' => 'uptime.acme-corp.com'])
            ->assertSessionHasNoErrors();
        $this->delete(route('organizations.status-pages.domain.destroy', [$this->organization, $withDomain]))
            ->assertSessionHasNoErrors();

        expect($withDomain->fresh()->custom_domain)->toBeNull();
    });

    test('notification channel creation is limited', function () {
        $this->entitlements->set(Feature::NotificationChannels, 1);
        NotificationChannel::factory()->for($this->organization)->create();

        $this->actingAs($this->owner)
            ->post(route('organizations.notification-channels.store', $this->organization), [
                'type' => 'email', 'name' => 'Ops', 'recipients' => 'ops@example.com',
            ])
            ->assertSessionHasErrors('limit');

        expect($this->organization->notificationChannels()->count())->toBe(1);
    });
});

describe('retention', function () {
    test('raw checks follow the retention of their organization', function () {
        $short = Organization::factory()->create();
        $this->entitlements->set(Feature::CheckRetentionDays, 7, $short);

        $longMonitor = Monitor::factory()->for($this->organization)->create();
        $shortMonitor = Monitor::factory()->for($short)->create();

        foreach ([$longMonitor, $shortMonitor] as $monitor) {
            MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(10)]);
            MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(3)]);
        }

        (new PurgeExpiredHistory)->handle();

        expect($longMonitor->checks()->count())->toBe(2)
            ->and($shortMonitor->checks()->count())->toBe(1);
    });
});

test('resuming through the action counts against the limit of the monitor organization only', function () {
    $other = Organization::factory()->create();
    $this->entitlements->set(Feature::Monitors, 0, $other);
    $paused = Monitor::factory()->for($this->organization)->paused()->create();

    app(SetMonitorEnabled::class)->handle($paused, true);

    expect($paused->fresh()->enabled)->toBeTrue();
});
