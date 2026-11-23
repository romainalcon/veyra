<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Notifications\Actions\EscalateIncidents;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Escalation re-checks the incident under its row lock (ADR-045): a
 * concurrent acknowledgement either commits first, and the step never fires,
 * or waits for the escalation to finish.
 */
test('escalation waits for the incident row lock and sees a concurrent acknowledgement', function () {
    $monitor = Monitor::factory()->create();
    $channel = NotificationChannel::factory()->create(['organization_id' => $monitor->organization_id]);
    $policy = EscalationPolicy::factory()->withSteps([[5, [$channel]]])->create(['organization_id' => $monitor->organization_id]);
    $monitor->update(['escalation_policy_id' => $policy->id]);
    MonitorState::query()->whereKey($monitor->id)->update(['status' => MonitorStatus::Down->value]);
    $incident = Incident::factory()->create(['monitor_id' => $monitor->id, 'started_at' => now()->subHour()]);

    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');
    $other = DB::connection('concurrent');

    $other->beginTransaction();
    $other->table('incidents')->where('id', $incident->id)->lockForUpdate()->first();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect(fn () => app(EscalateIncidents::class)->handle())->toThrow(QueryException::class);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

    $other->table('incidents')->where('id', $incident->id)->update(['acknowledged_at' => now()]);
    $other->commit();
    DB::disconnect('concurrent');

    expect(app(EscalateIncidents::class)->handle())->toBe(0)
        ->and(DB::table('notification_deliveries')->count())->toBe(0)
        ->and($incident->fresh()->escalation_step)->toBe(0);
});
