<?php

use App\Domain\Monitoring\Actions\ScheduleDueMonitors;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

/*
 * A second scheduler holding row locks must not cause double claiming:
 * ScheduleDueMonitors skips locked monitors and the next run claims them.
 */
test('concurrent schedulers never claim the same monitor twice', function () {
    $organization = Organization::factory()->create();
    $monitors = Monitor::factory()->count(4)->create([
        'organization_id' => $organization->id,
        'next_check_at' => now()->subMinute()->startOfSecond(),
    ]);
    $lockedIds = $monitors->take(2)->pluck('id')->all();

    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');
    $other = DB::connection('concurrent');

    $other->beginTransaction();
    $other->table('monitors')->whereIn('id', $lockedIds)->lockForUpdate()->get();

    $claimed = app(ScheduleDueMonitors::class)->handle();

    $other->rollBack();
    DB::disconnect('concurrent');

    expect($claimed)->toBe(2)
        ->and(DB::table('monitor_dispatches')->whereIn('monitor_id', $lockedIds)->count())->toBe(0);

    expect(app(ScheduleDueMonitors::class)->handle())->toBe(2)
        ->and(DB::table('monitor_dispatches')->count())->toBe(4)
        ->and(DB::table('monitor_dispatches')->distinct()->count('monitor_id'))->toBe(4);
});
