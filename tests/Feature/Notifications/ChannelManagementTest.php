<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/super-secret-token-xyz';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
});

test('admins create e-mail and Discord channels', function () {
    $this->actingAs($this->admin);

    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'email', 'name' => 'Ops', 'recipients' => 'Ops@Example.com, oncall@example.com', 'is_default' => '1',
    ])->assertSessionHasNoErrors();

    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'discord', 'name' => 'Alerts', 'webhook_url' => WEBHOOK,
    ])->assertSessionHasNoErrors();

    $email = NotificationChannel::where('type', ChannelType::Email)->sole();
    expect($email->recipients())->toBe(['ops@example.com', 'oncall@example.com'])
        ->and($email->is_default)->toBeTrue()
        ->and(NotificationChannel::where('type', ChannelType::Discord)->sole()->webhookUrl())->toBe(WEBHOOK);
});

test('channel input is validated', function (array $payload, string $field) {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), ['name' => 'X channel', ...$payload])
        ->assertSessionHasErrors($field);

    expect(NotificationChannel::count())->toBe(0);
})->with([
    'unknown type' => [['type' => 'sms'], 'type'],
    'no recipients' => [['type' => 'email', 'recipients' => ''], 'recipients'],
    'bad e-mail' => [['type' => 'email', 'recipients' => 'not-an-email'], 'recipients.0'],
    'too many recipients' => [['type' => 'email', 'recipients' => implode(',', array_map(fn ($i) => "u{$i}@example.com", range(1, 11)))], 'recipients'],
    'missing webhook' => [['type' => 'discord'], 'webhook_url'],
    'foreign webhook host' => [['type' => 'discord', 'webhook_url' => 'https://evil.example.com/api/webhooks/1234567/abcdefghijkl'], 'webhook_url'],
]);

test('secrets are encrypted at rest and never sent to the browser', function () {
    $this->actingAs($this->admin)->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'discord', 'name' => 'Alerts', 'webhook_url' => WEBHOOK,
    ]);

    expect(DB::table('notification_channels')->value('config_encrypted'))->not->toContain('super-secret-token');

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->has('channels', 1)
            ->where('channels.0.summary', 'Webhook …-xyz')
            ->missing('channels.0.config_encrypted'));

    expect($response->getContent())->not->toContain('super-secret-token');
});

test('updating a Discord channel keeps the webhook unless a new one is given', function () {
    $channel = NotificationChannel::factory()->discord(WEBHOOK)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => ''])
        ->assertSessionHasNoErrors();

    expect($channel->fresh())
        ->name->toBe('Renamed')
        ->webhookUrl()->toBe(WEBHOOK);
});

test('members and viewers cannot manage channels and never see recipients', function (OrganizationRole $role) {
    $user = memberOf($this->organization, $role);
    $channel = NotificationChannel::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($user)
        ->get(route('organizations.notification-channels.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('channels.0.recipients', []));

    $this->post(route('organizations.notification-channels.store', $this->organization), ['type' => 'email', 'name' => 'x', 'recipients' => 'a@example.com'])->assertForbidden();
    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'x', 'recipients' => 'a@example.com'])->assertForbidden();
    $this->delete(route('organizations.notification-channels.destroy', [$this->organization, $channel]))->assertForbidden();
    $this->post(route('organizations.notification-channels.test', [$this->organization, $channel]))->assertForbidden();
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('channels of another organization are not reachable', function () {
    $foreign = NotificationChannel::factory()->create(['organization_id' => organizationWith(User::factory()->create())->id]);

    $this->actingAs($this->admin);
    $this->put(route('organizations.notification-channels.update', [$this->organization, $foreign]), ['name' => 'x', 'recipients' => 'a@example.com'])->assertNotFound();
    $this->delete(route('organizations.notification-channels.destroy', [$this->organization, $foreign]))->assertNotFound();
    $this->post(route('organizations.notification-channels.test', [$this->organization, $foreign]))->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('monitors attach only channels of their organization, with defaults on creation', function () {
    $default = NotificationChannel::factory()->create(['organization_id' => $this->organization->id, 'is_default' => true]);
    $optional = NotificationChannel::factory()->create(['organization_id' => $this->organization->id]);
    $foreign = NotificationChannel::factory()->create(['organization_id' => organizationWith(User::factory()->create())->id]);

    $payload = [
        'name' => 'Site', 'url' => 'https://example.com/', 'method' => 'GET', 'interval' => 60, 'timeout' => 10,
        'expected_statuses' => '200-299', 'follow_redirects' => true,
    ];

    $this->actingAs($this->admin)->post(route('organizations.monitors.store', $this->organization), $payload);
    expect(Monitor::sole()->notificationChannels()->pluck('notification_channels.id')->all())->toBe([$default->id]);

    $monitor = Monitor::sole();
    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [
        ...$payload, 'headers_mode' => 'keep', 'notification_channels_submitted' => '1', 'notification_channel_ids' => [$foreign->id],
    ])->assertSessionHasErrors('notification_channel_ids.0');

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [
        ...$payload, 'headers_mode' => 'keep', 'notification_channels_submitted' => '1', 'notification_channel_ids' => [$optional->id],
    ])->assertSessionHasNoErrors();
    expect($monitor->notificationChannels()->pluck('notification_channels.id')->all())->toBe([$optional->id]);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [
        ...$payload, 'headers_mode' => 'keep', 'notification_channels_submitted' => '1',
    ])->assertSessionHasNoErrors();
    expect($monitor->notificationChannels()->count())->toBe(0);
});

test('sending a test reports the result and is rate limited', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $channel = NotificationChannel::factory()->discord(WEBHOOK)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('organizations.notification-channels.test', [$this->organization, $channel]))->assertRedirect();
    }
    $this->post(route('organizations.notification-channels.test', [$this->organization, $channel]))->assertTooManyRequests();

    Http::assertSentCount(5);
});
