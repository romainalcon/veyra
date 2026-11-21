<?php

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Heartbeats\PingOutcome;
use App\Domain\Monitoring\Heartbeats\RecordHeartbeatPing;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const PING_TOKEN = 'Ab3dEf6hIj9lMn2pQr5tUv8xYz1bCd4fGh7jKl0n';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00'));
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
    $this->monitor = Monitor::factory()->heartbeat(PING_TOKEN, 3600, 300)->create(['organization_id' => $this->organization->id]);
});

function heartbeatState(Monitor $monitor): MonitorStatus
{
    return MonitorState::findOrFail($monitor->id)->status;
}

test('members create a heartbeat and see its ping URL once', function () {
    $response = $this->actingAs($this->user)->post(route('organizations.monitors.store', $this->organization), [
        'type' => 'heartbeat',
        'name' => 'Nightly backup',
        'interval' => 86400,
        'grace_minutes' => 30,
    ])->assertSessionHasNoErrors();

    $monitor = Monitor::query()->where('name', 'Nightly backup')->sole();
    $pingUrl = $response->getSession()->get('inertia.flash_data')['pingUrl'] ?? null;
    $token = basename((string) $pingUrl);

    expect($monitor->type)->toBe(MonitorType::Heartbeat)
        ->and($monitor->interval_seconds)->toBe(86400)
        ->and($monitor->heartbeatConfig->grace_seconds)->toBe(1800)
        ->and($monitor->next_check_at->equalTo(now()->addSeconds(86400 + 1800)))->toBeTrue()
        ->and($pingUrl)->toStartWith(url('ping').'/')
        ->and(strlen($token))->toBe(40)
        ->and(DB::table('monitor_heartbeat_configs')->where('monitor_id', $monitor->id)->value('token_hash'))
        ->toBe(hash('sha256', $token))->not->toContain($token);

    $show = route('organizations.monitors.show', [$this->organization, $monitor]);

    // Shown on the page the creation redirects to, then never again.
    $this->get($show)->assertInertia(fn (Assert $page) => $page->hasFlash('pingUrl', $pingUrl));

    $this->get($show)
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('pingUrl')
            ->where('monitor.heartbeat.grace_seconds', 1800)
            ->where('monitor.heartbeat.last_ping_at', null)
            ->where('can.checkNow', false))
        ->assertDontSee($token)
        ->assertDontSee(hash('sha256', $token));
});

test('heartbeat input is validated', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), [
            'type' => 'heartbeat',
            'name' => 'Cron',
            'interval' => 3600,
            'grace_minutes' => 5,
            ...$payload,
        ])
        ->assertSessionHasErrors($field);
})->with([
    'unknown period' => [['interval' => 120], 'interval'],
    'grace missing' => [['grace_minutes' => null], 'grace_minutes'],
    'grace zero' => [['grace_minutes' => 0], 'grace_minutes'],
    'grace too long' => [['grace_minutes' => 1441], 'grace_minutes'],
]);

test('the first ping records a check and confirms up', function (string $method) {
    $this->call($method, '/ping/'.PING_TOKEN)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertCookieMissing(config('session.cookie'));

    $check = MonitorCheck::sole();
    $config = $this->monitor->heartbeatConfig->fresh();

    expect($check->success)->toBeTrue()
        ->and(MonitorDispatch::sole()->trigger)->toBe(DispatchTrigger::Ping)
        ->and(heartbeatState($this->monitor))->toBe(MonitorStatus::Up)
        ->and($config->last_ping_at->equalTo(now()))->toBeTrue()
        ->and($this->monitor->fresh()->next_check_at->equalTo(now()->addSeconds(3900)))->toBeTrue();
})->with(['GET', 'POST', 'HEAD']);

test('frequent pings record about one check per period', function () {
    $this->get('/ping/'.PING_TOKEN)->assertOk();
    $this->travel(10)->minutes();
    $this->post('/ping/'.PING_TOKEN)->assertOk();

    expect(MonitorCheck::count())->toBe(1)
        ->and($this->monitor->heartbeatConfig->fresh()->last_ping_at->equalTo(now()))->toBeTrue()
        ->and($this->monitor->fresh()->next_check_at->equalTo(now()->addSeconds(3900)))->toBeTrue();

    $this->travel(61)->minutes();
    $this->get('/ping/'.PING_TOKEN)->assertOk();

    expect(MonitorCheck::count())->toBe(2);
});

test('a failure signal confirms down at once and the next ping recovers', function () {
    $this->get('/ping/'.PING_TOKEN);
    $this->travel(1)->minutes();

    $this->post('/ping/'.PING_TOKEN.'/fail')->assertOk();

    expect(heartbeatState($this->monitor))->toBe(MonitorStatus::Down)
        ->and(MonitorCheck::query()->latest('scheduled_for')->first()->error_code)->toBe(ProbeErrorCode::HeartbeatFailed)
        ->and(Incident::sole()->status)->toBe(IncidentStatus::Open);

    $this->travel(1)->minutes();
    $this->get('/ping/'.PING_TOKEN)->assertOk();

    expect(heartbeatState($this->monitor))->toBe(MonitorStatus::Up)
        ->and(Incident::sole()->status)->toBe(IncidentStatus::Resolved)
        ->and(MonitorCheck::count())->toBe(3);
});

test('duplicate pings in the same second record one check', function () {
    $ping = app(RecordHeartbeatPing::class);

    expect($ping->handle(PING_TOKEN, failed: true))->toBe(PingOutcome::Recorded)
        ->and($ping->handle(PING_TOKEN, failed: true))->toBe(PingOutcome::Accepted)
        ->and(MonitorCheck::count())->toBe(1)
        ->and(MonitorDispatch::count())->toBe(1);
});

test('unknown tokens and deleted monitors answer 404 without recording', function () {
    $this->get('/ping/'.str_repeat('x', 40))->assertNotFound();
    $this->get('/ping/too-short')->assertNotFound();

    $this->monitor->delete();
    $this->get('/ping/'.PING_TOKEN)->assertNotFound();

    expect(MonitorCheck::count())->toBe(0);
});

test('pings of a paused monitor are acknowledged and ignored', function () {
    $this->monitor->update(['enabled' => false, 'next_check_at' => null]);

    $this->get('/ping/'.PING_TOKEN)->assertOk();

    expect(MonitorCheck::count())->toBe(0)
        ->and($this->monitor->heartbeatConfig->fresh()->last_ping_at)->toBeNull()
        ->and($this->monitor->fresh()->next_check_at)->toBeNull();
});

test('pings are rate limited per token', function () {
    foreach (range(1, 30) as $i) {
        $this->get('/ping/'.PING_TOKEN)->assertOk();
    }

    $this->get('/ping/'.PING_TOKEN)->assertTooManyRequests();
});

test('regenerating the token invalidates the previous ping URL', function () {
    $response = $this->actingAs($this->user)
        ->post(route('organizations.monitors.ping-token.store', [$this->organization, $this->monitor]))
        ->assertRedirect(route('organizations.monitors.show', [$this->organization, $this->monitor]));

    $newToken = basename((string) ($response->getSession()->get('inertia.flash_data')['pingUrl'] ?? ''));

    expect($newToken)->not->toBe(PING_TOKEN)->and(strlen($newToken))->toBe(40);

    $this->get('/ping/'.PING_TOKEN)->assertNotFound();
    $this->get('/ping/'.$newToken)->assertOk();
});

test('only managers can regenerate, and only for heartbeats', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $this->actingAs($viewer)
        ->post(route('organizations.monitors.ping-token.store', [$this->organization, $this->monitor]))
        ->assertForbidden();

    $http = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.ping-token.store', [$this->organization, $http]))
        ->assertNotFound();
});

test('heartbeats cannot be checked manually', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.checks.store', [$this->organization, $this->monitor]))
        ->assertForbidden();

    expect(MonitorDispatch::count())->toBe(0);
});

test('changing the period or grace moves the deadline from the last ping', function () {
    $this->get('/ping/'.PING_TOKEN);
    $lastPing = now();
    $this->travel(5)->minutes();

    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $this->monitor]), [
            'name' => 'Cron',
            'interval' => 21600,
            'grace_minutes' => 60,
        ])
        ->assertSessionHasNoErrors();

    expect($this->monitor->fresh()->next_check_at->equalTo($lastPing->addSeconds(21600 + 3600)))->toBeTrue()
        ->and($this->monitor->fresh()->heartbeatConfig->grace_seconds)->toBe(3600);
});

test('resuming a heartbeat gives it a full period and grace', function () {
    $this->actingAs($this->user)
        ->patch(route('organizations.monitors.enabled', [$this->organization, $this->monitor]), ['enabled' => false]);
    $this->travel(2)->hours();
    $this->patch(route('organizations.monitors.enabled', [$this->organization, $this->monitor]), ['enabled' => true]);

    expect($this->monitor->fresh()->next_check_at->equalTo(now()->addSeconds(3900)))->toBeTrue();
});

test('pings never start a session or require a CSRF token', function () {
    $response = $this->post('/ping/'.PING_TOKEN.'/fail');

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});
