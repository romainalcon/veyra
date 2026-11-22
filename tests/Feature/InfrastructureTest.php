<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

test('the health endpoint responds', function () {
    $this->get('/up')->assertOk();
});

test('the application uses MariaDB', function () {
    $version = DB::selectOne('select version() as version')->version;

    expect(config('database.default'))->toBe('mysql')
        ->and($version)->toContain('MariaDB');
});

test('horizon supervises the expected queues', function () {
    $queues = collect(config('horizon.defaults'))
        ->pluck('queue')
        ->flatten()
        ->sort()
        ->values()
        ->all();

    expect($queues)->toBe(['default', 'incidents', 'maintenance', 'notifications', 'probes']);
});

test('the redis queue retry window exceeds every worker timeout', function () {
    $maxTimeout = collect(config('horizon.defaults'))->max('timeout');

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($maxTimeout);
});

test('the horizon dashboard is restricted to configured administrators', function () {
    config(['veyra.admin_emails' => ['admin@example.com']]);

    $admin = User::factory()->create(['email' => 'admin@example.com']);
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

test('instance administrators are verified users listed in VEYRA_ADMIN_EMAILS, case-insensitively', function () {
    config(['veyra.admin_emails' => ['Admin@Example.com', 'pending@example.com']]);

    $admin = User::factory()->create(['email' => 'admin@example.com']);
    $unverified = User::factory()->unverified()->create(['email' => 'pending@example.com']);
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('administerInstance'))->toBeTrue()
        ->and(Gate::forUser($unverified)->allows('administerInstance'))->toBeFalse()
        ->and(Gate::forUser($unverified)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('administerInstance'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('administerInstance'))->toBeFalse();
});

test('nobody administers the instance when VEYRA_ADMIN_EMAILS is empty', function () {
    config(['veyra.admin_emails' => []]);

    expect(Gate::forUser(User::factory()->create())->allows('administerInstance'))->toBeFalse();
});

test('reverb does not accept client events by default', function () {
    expect(config('reverb.apps.apps.0.accept_client_events_from'))->toBe('none');
});
