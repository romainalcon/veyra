<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendNotificationDelivery::class]);
    $this->monitor = Monitor::factory()->create();
    $channel = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id]);
    $this->monitor->notificationChannels()->attach($channel->id);
    $this->travelTo('2026-11-22 12:00:00');
});

function reminders(): int
{
    return NotificationDelivery::where('event_type', NotificationEvent::Reminder)->count();
}

test('one reminder is sent for open, unacknowledged incidents past the delay', function () {
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-22 11:20:00']);
    Incident::factory()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-22 11:45:00']);

    $this->artisan('veyra:incidents:remind')->assertSuccessful();
    $this->artisan('veyra:incidents:remind')->assertSuccessful();

    expect(reminders())->toBe(1)
        ->and($incident->fresh()->reminder_sent_at)->not->toBeNull();
});

test('acknowledged or resolved incidents get no reminder', function () {
    Incident::factory()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-22 10:00:00', 'acknowledged_at' => now()]);
    Incident::factory()->resolved()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-22 10:00:00']);

    $this->artisan('veyra:incidents:remind');

    expect(reminders())->toBe(0);
});

test('the delay is configurable', function () {
    config(['veyra.notifications.reminder_after_minutes' => 120]);
    Incident::factory()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-22 11:00:00']);

    $this->artisan('veyra:incidents:remind');

    expect(reminders())->toBe(0);
});
