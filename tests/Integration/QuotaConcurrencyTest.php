<?php

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Models\Monitor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEntitlements;

/*
 * Exercises real MariaDB row locks across two connections. Data is committed
 * (DatabaseTruncation), so these tests must never run against SQLite.
 */
beforeEach(function () {
    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');

    $this->organization = organizationWith(User::factory()->create());
    $this->app->instance(Entitlements::class, (new FakeEntitlements)->set(Feature::Monitors, 2));
});

afterEach(fn () => DB::disconnect('concurrent'));

test('a limit check locks the organization until the addition commits', function () {
    $other = DB::connection('concurrent');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::transaction(function () use ($other) {
        app(Quota::class)->ensureCanAdd($this->organization, Feature::Monitors);

        // A concurrent addition must wait for this one before counting.
        expect(fn () => $other->transaction(fn () => $other->table('organizations')
            ->where('id', $this->organization->id)
            ->lockForUpdate()
            ->get()))->toThrow(QueryException::class);
    });
});

test('additions serialized by the lock never exceed the limit', function () {
    Monitor::factory()->for($this->organization)->create();

    DB::transaction(function () {
        app(Quota::class)->ensureCanAdd($this->organization, Feature::Monitors);
        Monitor::factory()->for($this->organization)->create();
    });

    expect(fn () => DB::transaction(function () {
        app(Quota::class)->ensureCanAdd($this->organization, Feature::Monitors);
        Monitor::factory()->for($this->organization)->create();
    }))->toThrow(LimitReached::class);

    expect($this->organization->monitors()->count())->toBe(2);
});

test('limits must be checked inside a transaction', function () {
    expect(fn () => app(Quota::class)->ensureCanAdd($this->organization, Feature::Monitors))
        ->toThrow(LogicException::class);
});
