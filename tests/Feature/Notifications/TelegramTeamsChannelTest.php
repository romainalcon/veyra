<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\NotificationMessage;
use App\Domain\Notifications\Channels\TeamsSender;
use App\Domain\Notifications\Channels\TelegramSender;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

const TELEGRAM_TOKEN = '12345:test-token-not-real-telegram-bot';
const TELEGRAM_CHAT = '-1001234567890';
const TEAMS_WORKFLOW = 'https://prod-00.westeurope.logic.azure.com:443/workflows/test-workflow/triggers/manual/paths/invoke?api-version=2016-06-01&sig=notarealsigAbcd';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
});

function chatIncidentIn(Organization $organization, string $monitorName = 'Checkout API'): Incident
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => $monitorName]);

    return Incident::factory()->create(['organization_id' => $organization->id, 'monitor_id' => $monitor->id, 'cause' => 'Timeout']);
}

function chatDelivery(NotificationChannel $channel, Incident $incident): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => NotificationEvent::Opened,
        'status' => DeliveryStatus::Pending,
    ]);
}

function sendChatDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('admins create Telegram channels; the bot token is encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'telegram', 'name' => 'On-call chat', 'token' => TELEGRAM_TOKEN, 'chat_id' => TELEGRAM_CHAT,
        ])
        ->assertSessionHasNoErrors();

    $channel = NotificationChannel::sole();

    expect($channel->type)->toBe(ChannelType::Telegram)
        ->and($channel->token())->toBe(TELEGRAM_TOKEN)
        ->and($channel->chatId())->toBe(TELEGRAM_CHAT)
        ->and(DB::table('notification_channels')->value('config_encrypted'))->not->toContain('test-token');

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('channels.0.summary', 'Chat -1001234567890'));
    expect($response->getContent())->not->toContain('test-token-not-real');
});

test('Telegram tokens and chat ids are validated', function (array $input, string $field) {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'telegram', 'name' => 'Chat', 'token' => TELEGRAM_TOKEN, 'chat_id' => TELEGRAM_CHAT, ...$input,
        ])
        ->assertSessionHasErrors($field);

    expect(NotificationChannel::count())->toBe(0);
})->with([
    'missing token' => [['token' => ''], 'token'],
    'malformed token' => [['token' => 'not-a-token'], 'token'],
    'token with a path' => [['token' => '12345:abcdefghijklmnopqrstuvwxyz0123/../x'], 'token'],
    'missing chat' => [['chat_id' => ''], 'chat_id'],
    'malformed chat' => [['chat_id' => 'chat 42'], 'chat_id'],
    'url field' => [['webhook_url' => 'https://api.telegram.org/bot1/sendMessage'], 'webhook_url'],
]);

test('Telegram deliveries send an escaped HTML message to the fixed API host', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
    $this->organization->update(['name' => 'Acme <b>&</b>']);
    $channel = NotificationChannel::factory()->telegram()->create(['organization_id' => $this->organization->id]);
    $incident = chatIncidentIn($this->organization, '<a href="https://evil.example">Click</a>');

    sendChatDelivery($delivery = chatDelivery($channel, $incident));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(function (Request $request) use ($incident): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'
            && $payload['chat_id'] === TELEGRAM_CHAT
            && $payload['parse_mode'] === 'HTML'
            && $payload['link_preview_options'] === ['is_disabled' => true]
            && ! str_contains($payload['text'], '<a href="https://evil')
            && str_contains($payload['text'], '&lt;a href=&quot;https://evil.example&quot;&gt;Click&lt;/a&gt;')
            && str_contains($payload['text'], '<i>Acme &lt;b&gt;&amp;&lt;/b&gt;</i>')
            && str_contains($payload['text'], '<b>Cause:</b> Timeout')
            && str_contains($payload['text'], "/incidents/{$incident->id}\">Open in Veyra</a>");
    });
});

test('oversized Telegram messages fall back to plain text instead of cutting HTML', function () {
    $message = new NotificationMessage(
        event: NotificationEvent::Opened,
        organizationName: str_repeat('"', 200),
        monitorName: str_repeat('"<&>', 250),
        cause: str_repeat('"', 256),
        startedAt: null,
        resolvedAt: null,
        durationSeconds: 60,
        acknowledgedBy: null,
        url: 'https://veyra.test/o/acme/incidents/1',
    );

    $payload = app(TelegramSender::class)->payload($message, TELEGRAM_CHAT);

    expect($payload)->not->toHaveKey('parse_mode')
        ->and(mb_strlen($payload['text']))->toBeLessThanOrEqual(TelegramSender::MAX_TEXT_LENGTH);
});

test('Telegram responses are classified, with short API descriptions only', function (mixed $response, bool $sent, bool $retryable, ?int $retryAfter, string $error) {
    Http::fake(['api.telegram.org/*' => $response]);
    $channel = NotificationChannel::factory()->telegram()->create(['organization_id' => $this->organization->id]);

    $result = app(TelegramSender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result->sent)->toBe($sent)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and((string) $result->error)->toBe($error);
})->with([
    'ok' => [fn () => Http::response(['ok' => true], 200), true, false, null, ''],
    'blocked' => [fn () => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403), false, false, null, 'Telegram rejected the message (HTTP 403: Forbidden: bot was blocked by the user).'],
    'chat not found' => [fn () => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400), false, false, null, 'Telegram rejected the message (HTTP 400: Bad Request: chat not found).'],
    'description with markup' => [fn () => Http::response(['ok' => false, 'description' => '<b>'.TELEGRAM_TOKEN.'</b>'], 401), false, false, null, 'Telegram rejected the message (HTTP 401).'],
    '429' => [fn () => Http::response(['ok' => false, 'parameters' => ['retry_after' => 14]], 429), false, true, 14, 'Rate limited by Telegram (HTTP 429).'],
    '502' => [fn () => Http::response('', 502), false, true, null, 'Telegram error (HTTP 502).'],
    'connection' => [fn () => fn () => throw new ConnectionException('cURL error 28: https://api.telegram.org/bot'.TELEGRAM_TOKEN), false, true, null, 'Could not reach Telegram.'],
]);

test('the Telegram token never reaches logs or stored errors', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'));
    $delivery = chatDelivery(NotificationChannel::factory()->telegram()->create(['organization_id' => $this->organization->id]), chatIncidentIn($this->organization));

    sendChatDelivery($delivery)->assertReleased(SendNotificationDelivery::BACKOFF[0]);

    expect($delivery->fresh()->last_error)->toBe('Could not reach Telegram.');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

test('editing a Telegram channel keeps the token and the chat unless new ones are given', function () {
    $channel = NotificationChannel::factory()->telegram()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($this->admin);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->token()->toBe(TELEGRAM_TOKEN)->chatId()->toBe(TELEGRAM_CHAT);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'chat_id' => '@veyra_alerts'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->token()->toBe(TELEGRAM_TOKEN)->chatId()->toBe('@veyra_alerts');
});

test('admins create Teams channels; the workflow URL is encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'teams', 'name' => 'Teams #ops', 'webhook_url' => TEAMS_WORKFLOW,
        ])
        ->assertSessionHasNoErrors();

    expect(NotificationChannel::sole())->type->toBe(ChannelType::Teams)->webhookUrl()->toBe(TEAMS_WORKFLOW)
        ->and(DB::table('notification_channels')->value('config_encrypted'))->not->toContain('notarealsig');

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('channels.0.summary', 'Webhook …Abcd'));
    expect($response->getContent())->not->toContain('notarealsig')->not->toContain('logic.azure.com');
});

test('only Teams workflow URLs on Microsoft hosts are accepted', function (string $url) {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'teams', 'name' => 'Teams', 'webhook_url' => $url,
        ])
        ->assertSessionHasErrors('webhook_url');
})->with([
    'other host' => ['https://example.com/workflows/x/triggers/manual/paths/invoke'],
    'lookalike host' => ['https://prod-00.logic.azure.com.evil.example/workflows/x/triggers/manual/paths/invoke'],
    'suffix without dot' => ['https://evillogic.azure.com/workflows/x/triggers/manual/paths/invoke'],
    'plain http' => ['http://prod-00.westeurope.logic.azure.com/workflows/x/triggers/manual/paths/invoke'],
    'other port' => ['https://prod-00.westeurope.logic.azure.com:8443/workflows/x/triggers/manual/paths/invoke'],
    'credentials' => ['https://user:pass@prod-00.westeurope.logic.azure.com/workflows/x/triggers'],
    'legacy connector' => ['https://acme.webhook.office.com/webhookb2/abc/IncomingWebhook/def/ghi'],
    'not a workflow' => ['https://prod-00.westeurope.logic.azure.com/admin'],
    'metadata' => ['https://169.254.169.254/workflows/'],
]);

test('Power Platform workflow URLs are accepted', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'teams', 'name' => 'Teams',
            'webhook_url' => 'https://default0a1b.2c.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/abc/triggers/manual/paths/invoke?api-version=1&sig=notreal',
        ])
        ->assertSessionHasNoErrors();
});

test('Teams deliveries post an Adaptive Card whose untrusted values are never Markdown', function () {
    Http::fake(['*' => Http::response('', 202)]);
    $channel = NotificationChannel::factory()->teams()->create(['organization_id' => $this->organization->id]);
    $incident = chatIncidentIn($this->organization, '[Click](https://evil.example) **now**');

    sendChatDelivery($delivery = chatDelivery($channel, $incident));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(function (Request $request) use ($incident): bool {
        $card = $request->data()['attachments'][0]['content'];
        $textBlocks = collect($card['body'])->where('type', 'TextBlock');

        // The HTTP client drops the default :443 port of the stored URL.
        return $request->url() === str_replace(':443', '', TEAMS_WORKFLOW)
            && $request->data()['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
            && $card['type'] === 'AdaptiveCard'
            && $textBlocks->isEmpty()
            && $card['body'][0]['inlines'][0]['text'] === '[Veyra] DOWN — [Click](https://evil.example) **now**'
            && $card['body'][0]['inlines'][0]['color'] === 'Attention'
            && $card['body'][2]['inlines'][0]['text'] === 'Monitor: '
            && $card['actions'][0]['type'] === 'Action.OpenUrl'
            && str_ends_with($card['actions'][0]['url'], "/incidents/{$incident->id}");
    });
});

test('Teams cards follow the organization language and the event colour', function (NotificationEvent $event, string $color) {
    $this->organization->update(['locale' => 'fr']);

    $card = app(TeamsSender::class)->payload(MessageFactory::forIncident(chatIncidentIn($this->organization), $event))['attachments'][0]['content'];

    expect($card['body'][0]['inlines'][0]['color'])->toBe($color)
        ->and($card['actions'][0]['title'])->toBe('Ouvrir dans Veyra')
        ->and($card['body'][2]['inlines'][0]['text'])->toBe('Moniteur : ');
})->with([
    [NotificationEvent::Opened, 'Attention'],
    [NotificationEvent::Resolved, 'Good'],
    [NotificationEvent::Acknowledged, 'Warning'],
]);

test('Teams responses are classified and a tampered URL is never called', function () {
    Http::fakeSequence('*')->push('', 429, ['Retry-After' => '5'])->push('', 400);
    $channel = NotificationChannel::factory()->teams()->create(['organization_id' => $this->organization->id]);
    $sender = app(TeamsSender::class);

    $limited = $sender->send($channel, MessageFactory::test($this->organization));
    $rejected = $sender->send($channel, MessageFactory::test($this->organization));

    expect($limited)->retryable->toBeTrue()->retryAfterSeconds->toBe(5)
        ->and($rejected)->retryable->toBeFalse()->error->toBe('Microsoft Teams rejected the notification (HTTP 400).');

    $channel->update(['config_encrypted' => ['webhook_url' => 'https://169.254.169.254/workflows/x']]);
    expect($sender->send($channel, MessageFactory::test($this->organization))->error)->toBe('Invalid Microsoft Teams workflow configuration.');
    Http::assertSentCount(2);
});
