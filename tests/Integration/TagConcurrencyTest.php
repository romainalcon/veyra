<?php

use App\Domain\Monitoring\Actions\SyncMonitorTags;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Tags are created under the organization row lock (ADR-047): a concurrent
 * save waits for it, then reuses the tag the other one committed instead of
 * hitting the unique (organization_id, name) key or exceeding the cap.
 */
test('tag creation waits for the organization lock and reuses a concurrently created tag', function () {
    $first = Monitor::factory()->create();
    $second = Monitor::factory()->create(['organization_id' => $first->organization_id]);

    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');
    $other = DB::connection('concurrent');

    $other->beginTransaction();
    $other->table('organizations')->where('id', $first->organization_id)->lockForUpdate()->first();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect(fn () => app(SyncMonitorTags::class)->handle($second, ['shared']))->toThrow(QueryException::class);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

    $other->rollBack();
    DB::disconnect('concurrent');

    app(SyncMonitorTags::class)->handle($first, ['shared']);
    app(SyncMonitorTags::class)->handle($second, ['shared']);

    expect(Tag::query()->where('organization_id', $first->organization_id)->pluck('name')->all())->toBe(['shared'])
        ->and($second->tags()->pluck('tags.name')->all())->toBe(['shared']);
});
