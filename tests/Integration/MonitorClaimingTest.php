<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Verifies the MariaDB locking semantics the scheduler relies on.
 */
test('the server supports SKIP LOCKED (MariaDB 10.6+)', function () {
    $version = (string) DB::selectOne('select version() as version')->version;
    preg_match('/^(\d+\.\d+)/', $version, $matches);

    expect($version)->toContain('MariaDB')
        ->and(version_compare($matches[1], '10.6', '>='))->toBeTrue();
});

test('concurrent schedulers claim disjoint batches of due monitors', function () {
    $organization = Organization::factory()->create();
    Monitor::factory()->count(6)->create([
        'organization_id' => $organization->id,
        'next_check_at' => CarbonImmutable::now()->subMinute(),
    ]);

    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');
    $other = DB::connection('concurrent');

    $claim = fn ($connection) => $connection->table('monitors')
        ->where('enabled', true)
        ->where('next_check_at', '<=', CarbonImmutable::now())
        ->orderBy('next_check_at')
        ->limit(3)
        ->lock('for update skip locked')
        ->pluck('id')
        ->all();

    DB::beginTransaction();
    $first = $claim(DB::connection());

    $other->beginTransaction();
    $second = $claim($other);
    $other->rollBack();
    DB::rollBack();
    DB::disconnect('concurrent');

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(3)
        ->and(array_intersect($first, $second))->toBe([]);
});
