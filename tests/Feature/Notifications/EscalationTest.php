<?php

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Notifications\Actions\EscalateIncidents;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\PagerDutySender;
use App\Domain\Notifications\Channels\WebhookSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendNotificationDelivery::class]);
    $this->travelTo('2026-11-22 12:00:00');

    $this->monitor = Monitor::factory()->create();
    $this->organizationId = $this->monitor->organization_id;
    $this->first = NotificationChannel::factory()->create(['organization_id' => $this->organizationId, 'name' => 'Team']);
    $this->second = NotificationChannel::factory()->create(['organization_id' => $this->organizationId, 'name' => 'Manager']);
    $this->monitor->notificationChannels()->attach($this->first->id);

    $this->policy = EscalationPolicy::factory()
        ->withSteps([[10, [$this->first]], [30, [$this->first, $this->second]]])
        ->create(['organization_id' => $this->organizationId]);
    $this->monitor->update(['escalation_policy_id' => $this->policy->id]);

    MonitorState::query()->whereKey($this->monitor->id)->update(['status' => MonitorStatus::Down->value]);
});

function escalations(?int $step = null): int
{
    return NotificationDelivery::query()
        ->where('event_type', NotificationEvent::Escalated)
        ->when($step !== null, fn ($query) => $query->where('escalation_step', $step))
        ->count();
}

function downIncident(Monitor $monitor, string $startedAt): Incident
{
    return Incident::factory()->create(['monitor_id' => $monitor->id, 'started_at' => $startedAt]);
}

test('steps fire once their delay since the incident start has elapsed', function () {
    $incident = downIncident($this->monitor, '2026-11-22 11:55:00');

    $this->artisan('veyra:incidents:escalate')->assertSuccessful();
    expect(escalations())->toBe(0)->and($incident->fresh()->escalation_step)->toBe(0);

    $this->travelTo('2026-11-22 12:05:00');
    $this->artisan('veyra:incidents:escalate');
    expect(escalations(1))->toBe(1)->and(escalations(2))->toBe(0)
        ->and($incident->fresh()->escalation_step)->toBe(1);

    $this->travelTo('2026-11-22 12:25:00');
    $this->artisan('veyra:incidents:escalate');
    expect(escalations(2))->toBe(2)->and($incident->fresh()->escalation_step)->toBe(2);

    Queue::assertPushed(SendNotificationDelivery::class, 3);
});

test('a channel used by several steps is notified once per step', function () {
    downIncident($this->monitor, '2026-11-22 11:00:00');

    $this->artisan('veyra:incidents:escalate');

    expect(NotificationDelivery::query()->where('channel_id', $this->first->id)->where('event_type', NotificationEvent::Escalated)->pluck('escalation_step')->sort()->values()->all())
        ->toBe([1, 2]);
});

test('every step already due fires in one run, in order, and is recorded on the timeline', function () {
    $incident = downIncident($this->monitor, '2026-11-22 11:00:00');

    expect(app(EscalateIncidents::class)->handle())->toBe(2);

    $events = $incident->events()->where('type', IncidentEventType::Escalated)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('metadata.step')->all())->toBe([1, 2])
        ->and($incident->fresh()->escalation_step)->toBe(2);
});

test('replayed and concurrent runs never fire a step twice', function () {
    downIncident($this->monitor, '2026-11-22 11:45:00');

    app(EscalateIncidents::class)->handle();
    app(EscalateIncidents::class)->handle();
    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(1);
});

test('the delivery key is a second guard when the incident step is reset', function () {
    $incident = downIncident($this->monitor, '2026-11-22 11:45:00');
    app(EscalateIncidents::class)->handle();

    $incident->forceFill(['escalation_step' => 0])->save();
    app(EscalateIncidents::class)->handle();

    expect(escalations())->toBe(1);
});

test('acknowledgement and recovery stop the chain', function () {
    $acknowledged = downIncident($this->monitor, '2026-11-22 11:00:00');
    $acknowledged->update(['acknowledged_at' => now()]);

    $other = Monitor::factory()->create(['organization_id' => $this->organizationId, 'escalation_policy_id' => $this->policy->id]);
    MonitorState::query()->whereKey($other->id)->update(['status' => MonitorStatus::Down->value]);
    Incident::factory()->resolved()->create(['monitor_id' => $other->id, 'started_at' => '2026-11-22 11:00:00']);

    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(0);
});

test('maintenance, missing data and paused monitors postpone escalation', function (MonitorStatus $status) {
    $incident = downIncident($this->monitor, '2026-11-22 11:00:00');
    MonitorState::query()->whereKey($this->monitor->id)->update(['status' => $status->value]);

    $this->artisan('veyra:incidents:escalate');
    expect(escalations())->toBe(0);

    MonitorState::query()->whereKey($this->monitor->id)->update(['status' => MonitorStatus::Down->value]);
    $this->artisan('veyra:incidents:escalate');
    expect(escalations())->toBe(3)->and($incident->fresh()->escalation_step)->toBe(2);
})->with([
    'maintenance' => MonitorStatus::Maintenance,
    'unknown (stale or paused)' => MonitorStatus::Unknown,
]);

test('monitors without a policy use the organization default, or nothing', function () {
    $this->monitor->update(['escalation_policy_id' => null]);
    downIncident($this->monitor, '2026-11-22 11:00:00');

    $this->artisan('veyra:incidents:escalate');
    expect(escalations())->toBe(0);

    EscalationPolicy::factory()->default()->withSteps([[5, [$this->second]]])->create(['organization_id' => $this->organizationId]);
    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(1)
        ->and(NotificationDelivery::query()->where('event_type', NotificationEvent::Escalated)->sole()->channel_id)->toBe($this->second->id);
});

test('the default policy of another organization is never used', function () {
    $this->monitor->update(['escalation_policy_id' => null]);
    $foreign = Organization::factory()->create();
    $foreignChannel = NotificationChannel::factory()->create(['organization_id' => $foreign->id]);
    EscalationPolicy::factory()->default()->withSteps([[1, [$foreignChannel]]])->create(['organization_id' => $foreign->id]);
    downIncident($this->monitor, '2026-11-22 11:00:00');

    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(0);
});

test('channels of another organization attached to a step are ignored', function () {
    $foreignChannel = NotificationChannel::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $this->policy->steps()->where('position', 1)->first()->channels()->sync([$foreignChannel->id]);
    $incident = downIncident($this->monitor, '2026-11-22 11:45:00');

    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(0)->and($incident->fresh()->escalation_step)->toBe(1);
});

test('disabled channels are skipped while the step still advances', function () {
    $this->first->update(['enabled' => false]);
    $incident = downIncident($this->monitor, '2026-11-22 11:45:00');

    $this->artisan('veyra:incidents:escalate');

    expect(escalations())->toBe(0)->and($incident->fresh()->escalation_step)->toBe(1);
});

test('the escalation message names the step in the organization language', function () {
    Organization::query()->whereKey($this->organizationId)->update(['locale' => 'fr']);
    $incident = downIncident($this->monitor, '2026-11-22 11:40:00');
    app(EscalateIncidents::class)->handle();

    $delivery = NotificationDelivery::query()->where('event_type', NotificationEvent::Escalated)->sole();
    $message = MessageFactory::forIncident($incident->fresh(), NotificationEvent::Escalated, $delivery->id, $delivery->escalation_step, $delivery->created_at);

    expect($message->title())->toContain('ESCALADÉ')
        ->and($message->summary())->toContain('escaladé')
        ->and($message->escalationStep)->toBe(1)
        ->and($message->occurredAt)->toBe('2026-11-22 12:00:00');

    $payload = app(WebhookSender::class)->payload($message, $delivery->id);
    expect($payload['event'])->toBe('incident.escalated')
        ->and($payload['escalation'])->toBe(['step' => 1]);
});

test('PagerDuty receives an escalation as a trigger of the incident alert', function () {
    $incident = downIncident($this->monitor, '2026-11-22 11:30:00');
    $message = MessageFactory::forIncident($incident, NotificationEvent::Escalated, 'delivery', 1, now()->toImmutable());
    $event = app(PagerDutySender::class)->event($message, str_repeat('a', 32), 'trigger');

    expect($event['dedup_key'])->toBe("veyra-incident-{$incident->id}")
        ->and($event['payload']['severity'])->toBe('critical');
});
