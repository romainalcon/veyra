<?php

use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Events\IncidentResolved;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Realtime\OrganizationUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->monitor = Monitor::factory()->create();
});

test('domain events are dispatched once per transition', function () {
    Event::fake([MonitorStateChanged::class, IncidentOpened::class, IncidentResolved::class, OrganizationUpdate::class]);

    foreach (['00', '01', '02', '03'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }
    recordCheck($this->monitor, true, '2026-11-22 10:04:00');
    recordCheck($this->monitor, true, '2026-11-22 10:05:00');

    Event::assertDispatchedTimes(IncidentOpened::class, 1);
    Event::assertDispatchedTimes(IncidentResolved::class, 1);
    Event::assertDispatched(MonitorStateChanged::class, fn (MonitorStateChanged $event) => $event->to->value === 'down');
    Event::assertDispatched(MonitorStateChanged::class, fn (MonitorStateChanged $event) => $event->to->value === 'up');
});

test('real-time updates go to the private organization channel with identifiers only', function () {
    Event::fake([OrganizationUpdate::class]);

    foreach (['00', '01', '02'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }

    Event::assertDispatched(OrganizationUpdate::class, function (OrganizationUpdate $update) {
        $allowedKeys = ['monitor_id', 'incident_id', 'status'];

        return $update->broadcastOn()->name === 'private-organizations.'.$this->monitor->organization_id
            && array_diff(array_keys($update->broadcastWith()), $allowedKeys) === []
            && $update->broadcastQueue() === 'incidents';
    });

    Event::assertDispatched(OrganizationUpdate::class, fn (OrganizationUpdate $update) => $update->broadcastAs() === OrganizationUpdate::INCIDENT_UPDATED);
    Event::assertDispatched(OrganizationUpdate::class, fn (OrganizationUpdate $update) => $update->broadcastAs() === OrganizationUpdate::MONITOR_STATE_CHANGED
        && $update->broadcastWith()['status'] === 'down');
});

test('nothing is broadcast when the transaction rolls back', function () {
    Event::fake([OrganizationUpdate::class, IncidentOpened::class]);

    try {
        DB::transaction(function () {
            foreach (['00', '01', '02'] as $minute) {
                recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
            }

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(IncidentOpened::class);
    Event::assertNotDispatched(OrganizationUpdate::class);
});
