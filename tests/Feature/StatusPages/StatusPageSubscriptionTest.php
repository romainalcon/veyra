<?php

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\StatusPages\Jobs\SendSubscriptionConfirmation;
use App\Domain\StatusPages\Mail\SubscriptionConfirmationMail;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use App\Domain\StatusPages\Support\SubscriptionLinks;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeEntitlements;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['locale' => 'fr']);
    $this->page = StatusPage::factory()->withSubscriptions()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->page->components()->create([
        'monitor_id' => Monitor::factory()->create(['organization_id' => $this->organization->id])->id,
        'display_name' => 'API',
    ]);
});

function subscribeVisitor(string $email = 'visitor@example.com', array $extra = []): TestResponse
{
    return test()->postJson('/status/acme/subscribe', ['email' => $email, ...$extra]);
}

test('subscribing stores an encrypted, pending subscriber and queues a confirmation, without cookies', function () {
    Queue::fake();

    $response = subscribeVisitor('Visitor@Example.com ');

    $response->assertStatus(202)->assertJsonStructure(['message']);
    expect($response->headers->getCookies())->toBe([]);

    $subscriber = StatusPageSubscriber::query()->sole();
    $raw = DB::table('status_page_subscribers')->value('email');

    expect($subscriber->email)->toBe('visitor@example.com')
        ->and($subscriber->confirmed_at)->toBeNull()
        ->and($subscriber->organization_id)->toBe($this->organization->id)
        ->and($raw)->not->toContain('visitor@example.com');

    Queue::assertPushed(SendSubscriptionConfirmation::class, fn ($job) => $job->subscriberId === $subscriber->id && $job->queue === 'notifications');
});

test('new, pending and confirmed addresses get the same answer', function () {
    Queue::fake();

    $first = subscribeVisitor()->assertStatus(202)->json();
    $pending = subscribeVisitor()->assertStatus(202)->json();

    StatusPageSubscriber::query()->update(['confirmed_at' => now()]);
    $confirmed = subscribeVisitor()->assertStatus(202)->json();

    expect($pending)->toBe($first)->and($confirmed)->toBe($first)
        ->and(StatusPageSubscriber::query()->count())->toBe(1);

    // One confirmation only: the second request is within the resend interval, the third is confirmed.
    Queue::assertPushed(SendSubscriptionConfirmation::class, 1);
});

test('a pending address gets a new confirmation once the resend interval has passed', function () {
    Queue::fake();

    subscribeVisitor();
    $this->travel(11)->minutes();
    subscribeVisitor();

    Queue::assertPushed(SendSubscriptionConfirmation::class, 2);
});

test('invalid addresses are rejected and the honeypot silently drops bots', function () {
    Queue::fake();

    subscribeVisitor('not-an-email')->assertStatus(422)->assertJsonValidationErrors('email');
    subscribeVisitor('bot@example.com', ['website' => 'https://spam.example'])->assertStatus(202);

    expect(StatusPageSubscriber::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('pages without subscriptions, unpublished and unknown pages accept nobody', function () {
    $this->page->update(['subscriptions_enabled' => false]);
    subscribeVisitor()->assertStatus(422)->assertJsonValidationErrors('email');

    StatusPage::factory()->unpublished()->withSubscriptions()->create(['slug' => 'draft']);
    $this->postJson('/status/draft/subscribe', ['email' => 'visitor@example.com'])->assertNotFound();
    $this->postJson('/status/nope/subscribe', ['email' => 'visitor@example.com'])->assertNotFound();

    expect(StatusPageSubscriber::query()->count())->toBe(0);
});

test('subscriptions are rate-limited per IP', function () {
    Queue::fake();

    foreach (range(1, 5) as $index) {
        subscribeVisitor("visitor{$index}@example.com")->assertStatus(202);
    }

    subscribeVisitor('visitor6@example.com')->assertStatus(429);
    expect(StatusPageSubscriber::query()->count())->toBe(5);
});

test('subscriptions are rate-limited per page, across IPs', function () {
    Queue::fake();
    config(['veyra.status_pages.subscriptions_per_page_per_hour' => 2]);

    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $index => $ip) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/status/acme/subscribe', ['email' => "v{$index}@example.com"]);
        expect($response->status())->toBe($index < 2 ? 202 : 429);
    }
});

test('the subscriber limit stops new subscriptions without telling the visitor about plans', function () {
    Queue::fake();
    $this->app->instance(Entitlements::class, (new FakeEntitlements)->set(Feature::StatusPageSubscribers, 1));

    subscribeVisitor('first@example.com')->assertStatus(202);
    subscribeVisitor('second@example.com')->assertStatus(422)->assertJsonMissing(['limited to']);

    expect(StatusPageSubscriber::query()->count())->toBe(1);
});

test('the confirmation e-mail is in the organization language and links to a signed, expiring URL', function () {
    Mail::fake();

    subscribeVisitor();
    $subscriber = StatusPageSubscriber::query()->sole();

    (new SendSubscriptionConfirmation($subscriber->id))->handle(app(Mailer::class));

    Mail::assertSent(SubscriptionConfirmationMail::class, function (SubscriptionConfirmationMail $mail) use ($subscriber) {
        return $mail->hasTo('visitor@example.com')
            && $mail->locale === 'fr'
            && str_contains($mail->confirmUrl, "/status-subscriptions/{$subscriber->id}/confirm")
            && str_contains($mail->confirmUrl, 'expires=')
            && str_contains($mail->render(), 'Confirmer mon abonnement');
    });
});

test('opening the confirmation link only shows a button; posting it confirms', function () {
    Queue::fake();
    subscribeVisitor();
    $subscriber = StatusPageSubscriber::query()->sole();
    $url = SubscriptionLinks::confirm($subscriber);

    $response = $this->get($url);
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Subscription')
        ->where('state', 'confirm')
        ->where('page.title', 'Acme status')
        ->where('actionUrl', $url));
    expect($response->headers->getCookies())->toBe([])
        ->and($subscriber->fresh()->confirmed_at)->toBeNull();

    $this->post($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'confirmed'));
    $confirmedAt = $subscriber->fresh()->confirmed_at;
    expect($confirmedAt)->not->toBeNull();

    $this->travel(1)->hours();
    $this->post($url)->assertOk();
    expect($subscriber->fresh()->confirmed_at->equalTo($confirmedAt))->toBeTrue();
});

test('expired, tampered or foreign confirmation links do nothing', function () {
    Queue::fake();
    subscribeVisitor('first@example.com');
    subscribeVisitor('second@example.com');
    [$first, $second] = StatusPageSubscriber::query()->orderBy('created_at')->orderBy('id')->get()->all();

    $tampered = str_replace($first->id, $second->id, SubscriptionLinks::confirm($first));
    $this->post($tampered)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'invalid')->where('actionUrl', null));

    $this->post(URL::route('status-pages.subscriptions.confirm', ['subscriber' => $first->id]))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));

    $expired = SubscriptionLinks::confirm($first);
    $this->travel(49)->hours();
    $this->post($expired)->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));

    expect(StatusPageSubscriber::query()->whereNotNull('confirmed_at')->count())->toBe(0);
});

test('unsubscribe links are permanent, show a button on GET and remove the subscriber on POST', function () {
    Queue::fake();
    subscribeVisitor();
    $subscriber = StatusPageSubscriber::query()->sole();
    $url = SubscriptionLinks::unsubscribe($subscriber);

    $this->travel(400)->days();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unsubscribe'));
    expect(StatusPageSubscriber::query()->count())->toBe(1);

    // RFC 8058 one-click: the mail client posts this body without any session.
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unsubscribed'));
    expect(StatusPageSubscriber::query()->count())->toBe(0);

    $this->post($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unsubscribed'));
    $this->post(URL::route('status-pages.subscriptions.unsubscribe', ['subscriber' => $subscriber->id]))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
});

test('subscriber addresses never reach the management page or the logs', function () {
    Queue::fake();
    Log::spy();
    subscribeVisitor('secret-visitor@example.com');
    StatusPageSubscriber::query()->update(['confirmed_at' => now()]);
    subscribeVisitor('pending@example.com');

    $response = $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $this->page]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('audience.subscribers.confirmed', 1)
            ->where('audience.subscribers.pending', 1))
        ->assertDontSee('secret-visitor@example.com')
        ->assertDontSee('pending@example.com');

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($message) => str_contains((string) $message, 'example.com'))]);
});

test('subscriber rows are isolated per page and organization', function () {
    Queue::fake();
    $other = StatusPage::factory()->withSubscriptions()->create(['slug' => 'other-org']);

    subscribeVisitor();
    $this->postJson('/status/other-org/subscribe', ['email' => 'visitor@example.com'])->assertStatus(202);

    expect(StatusPageSubscriber::query()->where('status_page_id', $this->page->id)->value('organization_id'))->toBe($this->organization->id)
        ->and(StatusPageSubscriber::query()->where('status_page_id', $other->id)->value('organization_id'))->toBe($other->organization_id);
});

test('unconfirmed subscriptions are purged after a week, confirmed ones are kept', function () {
    Queue::fake();
    subscribeVisitor('pending@example.com');
    subscribeVisitor('confirmed@example.com');
    StatusPageSubscriber::query()->get()->last()->update(['confirmed_at' => now()]);

    $this->travel(8)->days();
    (new PurgeExpiredHistory)->handle();

    expect(StatusPageSubscriber::query()->count())->toBe(1)
        ->and(StatusPageSubscriber::query()->sole()->confirmed_at)->not->toBeNull();
});
