<?php

use App\Domain\System\CheckResult;
use App\Domain\System\ConfigurationDoctor;
use App\Domain\System\HealthCheck;
use App\Domain\System\SchedulerHeartbeat;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the public health endpoint only exposes the status and version', function () {
    config(['veyra.version' => '1.0.0']);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonStructure(['status', 'version'])
        ->assertJsonMissingPath('checks')
        ->assertJsonPath('version', '1.0.0');
});

test('details require the bearer token', function () {
    config(['veyra.health_token' => 'secret-token']);

    $this->getJson('/health', ['Authorization' => 'Bearer wrong'])->assertJsonMissingPath('checks');

    $this->getJson('/health', ['Authorization' => 'Bearer secret-token'])
        ->assertJsonPath('checks.0.name', 'database')
        ->assertJsonPath('checks.0.status', 'ok');
});

test('a stopped scheduler or Horizon degrades the instance without taking it down', function () {
    $results = [
        CheckResult::ok('database', 'MariaDB 11.4', critical: true),
        CheckResult::fail('scheduler', 'stopped'),
    ];

    expect(HealthCheck::overall($results))->toBe('degraded')
        ->and(HealthCheck::overall([CheckResult::fail('database', 'down', critical: true)]))->toBe('down');
});

test('the scheduler heartbeat is recorded by the scheduled command', function () {
    expect(SchedulerHeartbeat::isAlive())->toBeFalse();

    $this->artisan('veyra:heartbeat')->assertSuccessful();

    expect(SchedulerHeartbeat::isAlive())->toBeTrue();

    $this->travel(4)->minutes();
    expect(SchedulerHeartbeat::isAlive())->toBeFalse();
});

test('a paused monitoring pipeline is surfaced to users', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user);

    $this->actingAs($user)
        ->get(route('organizations.dashboard', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('system.monitoring.paused', true)
            ->where('system.monitoring.reasons', fn ($reasons) => collect($reasons)->contains('scheduler')));
});

test('the doctor reports blocking production issues with a failing exit code', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'mail.default' => 'log', 'session.secure' => false]);

    $this->artisan('veyra:doctor')->assertFailed();

    config(['app.debug' => false, 'mail.default' => 'smtp', 'session.secure' => true, 'app.url' => 'https://status.example.com', 'queue.default' => 'redis']);

    $this->artisan('veyra:doctor')->assertSuccessful();
});

test('the doctor fails a cloud edition running without cloud entitlements', function () {
    config(['veyra.edition' => 'cloud']);

    $result = collect(app(ConfigurationDoctor::class)->run())->firstWhere('name', 'entitlements');

    expect($result->status)->toBe('fail');

    config(['veyra.edition' => 'community']);

    expect(collect(app(ConfigurationDoctor::class)->run())->firstWhere('name', 'entitlements')->status)->toBe('ok');
});
