<?php

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * V1 "Done means" (docs/ROADMAP.md): a clean install can register, create an
 * organization and an HTTP monitor, execute scheduled checks, detect confirmed
 * downtime, notify, recover, and display history. A probe-worker outage must
 * not masquerade as monitored-service downtime.
 */
test('the complete V1 journey works end to end', function () {
    Mail::fake();
    $this->travelTo('2026-12-01 09:00:00');

    // 1. Register and verify the e-mail address.
    $this->post(route('register.store'), [
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::sole();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]))
        ->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // 2. Organization (onboarding), notification channel and monitor.
    $this->get(route('dashboard'))->assertRedirect(route('organizations.create'));
    $this->post(route('organizations.store'), ['name' => 'Acme'])->assertRedirect();
    $organization = Organization::sole();

    $this->post(route('organizations.notification-channels.store', $organization), [
        'type' => 'email', 'name' => 'On-call', 'recipients' => 'oncall@example.com', 'is_default' => '1',
    ])->assertSessionHasNoErrors();

    $this->post(route('organizations.monitors.store', $organization), [
        'name' => 'Website', 'url' => 'https://example.com/health', 'method' => 'GET', 'interval' => 60,
        'timeout' => 10, 'expected_statuses' => '200-299', 'follow_redirects' => '1',
    ])->assertSessionHasNoErrors();
    $monitor = Monitor::sole();

    // 3. Scheduled checks: up, confirmed downtime, recovery.
    Http::fakeSequence('example.com/*')
        ->push('', 200)->push('', 200)
        ->push('', 503)->push('', 503)->push('', 503)
        ->push('', 200)->push('', 200);

    $cycle = function () {
        $this->artisan('veyra:monitors:dispatch')->assertSuccessful();
        $this->travel(60)->seconds();
    };

    $cycle();
    $cycle();
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Up);

    $cycle();
    $cycle();
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Degraded)
        ->and(Incident::count())->toBe(0);

    $cycle();
    $incident = Incident::sole();
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Down)
        ->and($incident->isOpen())->toBeTrue();
    Mail::assertSent(IncidentNotificationMail::class, fn ($mail) => $mail->envelope()->subject === '[Veyra] DOWN — Website' && $mail->hasTo('oncall@example.com'));

    $cycle();
    $cycle();
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Up)
        ->and($incident->fresh()->status)->toBe(IncidentStatus::Resolved);
    Mail::assertSent(IncidentNotificationMail::class, fn ($mail) => $mail->envelope()->subject === '[Veyra] RESOLVED — Website');

    // 4. History and uptime are visible.
    expect(MonitorCheck::count())->toBe(7);
    $this->get(route('organizations.monitors.show', [$organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitor.state.status', 'up')
            ->has('checks.data', 7)
            ->has('incidents', 1)
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('stats.uptime.24h.uptime', round(4 / 7 * 100, 3))));

    // 5. Worker outage: no more checks. The state becomes UNKNOWN, no incident
    //    is opened, and users are told monitoring is paused.
    $this->travel(15)->minutes();
    $this->artisan('veyra:monitors:detect-stale')->assertSuccessful();

    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Unknown)
        ->and(Incident::count())->toBe(1)
        ->and(Incident::where('status', IncidentStatus::Open)->count())->toBe(0);

    $this->get(route('organizations.dashboard', $organization))
        ->assertInertia(fn (Assert $page) => $page->where('system.monitoring.paused', true));
});
