<?php

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Notifications\OrganizationInvitationNotification;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['locale' => 'fr']);
});

test('tenant pages use the organization language, whatever the browser asks', function () {
    $this->actingAs($this->owner)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(route('organizations.dashboard', $this->organization))
        ->assertOk()
        ->assertSee('<html lang="fr"', false)
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

test('an organization language never leaks into another organization', function () {
    $english = organizationWith($this->owner, attributes: ['locale' => 'en']);

    $this->actingAs($this->owner)->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));

    $this->actingAs($this->owner)->get(route('organizations.dashboard', $english))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('pages outside an organization follow the browser language', function (?string $header, string $expected) {
    $request = $this->actingAs($this->owner);

    if ($header !== null) {
        $request = $request->withHeader('Accept-Language', $header);
    }

    $request->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', $expected));
})->with([
    'French' => ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'],
    'regional variant' => ['fr-CA', 'fr'],
    'first supported wins' => ['de-DE,fr;q=0.8,en;q=0.5', 'fr'],
    'unsupported' => ['de-DE', 'en'],
    'no header' => [null, 'en'],
]);

test('guest pages follow the browser language', function () {
    $this->withHeader('Accept-Language', 'fr')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="fr"', false);
});

test('validation messages use the organization language', function () {
    $this->actingAs($this->owner)
        ->from(route('organizations.edit', $this->organization))
        ->patch(route('organizations.update', $this->organization), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Le champ nom est obligatoire.']);
});

test('admins can change the organization language', function () {
    $admin = memberOf($this->organization, OrganizationRole::Admin);

    $this->actingAs($admin)
        ->patch(route('organizations.update', $this->organization), ['name' => $this->organization->name, 'locale' => 'en'])
        ->assertRedirect(route('organizations.edit', $this->organization))
        ->assertInertiaFlash('toast.message', 'Organization updated.');

    expect($this->organization->refresh()->locale)->toBe('en');
});

test('the confirmation is shown in the newly chosen language', function () {
    $this->organization->update(['locale' => 'en']);

    $this->actingAs($this->owner)
        ->patch(route('organizations.update', $this->organization), ['name' => $this->organization->name, 'locale' => 'fr'])
        ->assertInertiaFlash('toast.message', 'Organisation mise à jour.');
});

test('omitting the language keeps the current one', function () {
    $this->actingAs($this->owner)
        ->patch(route('organizations.update', $this->organization), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();

    expect($this->organization->refresh())
        ->name->toBe('Renamed')
        ->locale->toBe('fr');
});

test('unsupported languages are rejected', function () {
    $this->actingAs($this->owner)
        ->patch(route('organizations.update', $this->organization), ['name' => 'Acme', 'locale' => 'de'])
        ->assertSessionHasErrors('locale');

    expect($this->organization->refresh()->locale)->toBe('fr');
});

test('members and viewers cannot change the language', function (OrganizationRole $role) {
    $user = memberOf($this->organization, $role);

    $this->actingAs($user)
        ->patch(route('organizations.update', $this->organization), ['name' => $this->organization->name, 'locale' => 'en'])
        ->assertForbidden();

    expect($this->organization->refresh()->locale)->toBe('fr');
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('non-members cannot change the language of another organization', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('organizations.update', $this->organization), ['name' => 'Taken', 'locale' => 'en'])
        ->assertNotFound();

    expect($this->organization->refresh()->locale)->toBe('fr');
});

test('settings expose the language and the available options', function () {
    $this->actingAs($this->owner)
        ->get(route('organizations.edit', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organization.locale', 'fr')
            ->where('locales', [['code' => 'en', 'name' => 'English'], ['code' => 'fr', 'name' => 'Français']]));
});

test('a new organization takes the language of the request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'fr-FR')
        ->post(route('organizations.store'), ['name' => 'Bistro'])
        ->assertRedirect();

    expect($user->organizations()->sole()->locale)->toBe('fr');
});

test('public status pages use the organization language, not the visitor one', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    MonitorState::whereKey($monitor->id)->update(['status' => MonitorStatus::Down->value]);
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'bistro']);
    $page->components()->create(['monitor_id' => $monitor->id, 'display_name' => 'API']);
    recordCheck($monitor, false, now()->subMinutes(3)->toDateTimeString());
    recordCheck($monitor, false, now()->subMinutes(2)->toDateTimeString());
    recordCheck($monitor, false, now()->subMinute()->toDateTimeString());
    Cache::flush();

    $this->withHeader('Accept-Language', 'en-US')
        ->get('/status/bistro')
        ->assertOk()
        ->assertSee('<html lang="fr"', false)
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('locale', 'fr')
            ->where('snapshot.incidents.0.title', 'Panne — API'));
});

test('status page snapshots are cached per language and all forgotten together', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id]);
    Cache::put(StatusPageSnapshot::cacheKey($page, 'en'), ['stale' => true], 60);
    Cache::put(StatusPageSnapshot::cacheKey($page, 'fr'), ['stale' => true], 60);

    expect(StatusPageSnapshot::cacheKey($page, 'en'))->not->toBe(StatusPageSnapshot::cacheKey($page, 'fr'));

    StatusPageSnapshot::forget($page);

    expect(Cache::has(StatusPageSnapshot::cacheKey($page, 'en')))->toBeFalse()
        ->and(Cache::has(StatusPageSnapshot::cacheKey($page, 'fr')))->toBeFalse();
});

test('incident notifications are written in the organization language', function () {
    Mail::fake();
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Checkout API']);
    $email = NotificationChannel::factory()->create(['organization_id' => $this->organization->id, 'config_encrypted' => ['recipients' => ['ops@example.com']]]);
    $discord = NotificationChannel::factory()->discord('https://discord.com/api/webhooks/123456789012345678/secret-token')->create(['organization_id' => $this->organization->id]);
    $monitor->notificationChannels()->attach([$email->id, $discord->id]);

    // The worker process runs in English: the message must not depend on it.
    app()->setLocale('en');

    foreach (['00', '01', '02'] as $minute) {
        recordCheck($monitor, false, "2026-11-22 10:{$minute}:00");
    }

    Mail::assertSent(IncidentNotificationMail::class, function (IncidentNotificationMail $mail): bool {
        $html = $mail->render();

        return $mail->envelope()->subject === '[Veyra] EN PANNE — Checkout API'
            && $mail->notification->summary() === 'Checkout API est en panne.'
            && str_contains($html, 'Ouvrir dans Veyra')
            && str_contains($html, 'Organisation');
    });

    Http::assertSent(fn (Request $request) => $request['embeds'][0]['title'] === '[Veyra] EN PANNE — Checkout API'
        && $request['embeds'][0]['fields'][0]['name'] === 'Moniteur');

    expect(app()->getLocale())->toBe('en');
});

test('invitations are sent in the organization language', function () {
    Notification::fake();

    $this->actingAs($this->owner)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => 'new@example.com', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, fn (OrganizationInvitationNotification $notification) => $notification->locale === 'fr');
});

test('monitor validation errors from the domain are shown in the organization language', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.monitors.store', $this->organization), [
            'name' => 'Website',
            'url' => 'https://user:secret@example.com/health',
            'method' => 'GET',
            'interval' => 60,
            'timeout' => 10,
            'expected_statuses' => '200-299, abc',
        ])
        ->assertSessionHasErrors([
            'url' => 'Les identifiants dans l’URL ne sont pas autorisés ; utilisez plutôt un en-tête personnalisé.',
            'expected_statuses' => 'Code ou plage de codes invalide : abc',
        ]);
});

test('domain exceptions keep an English message for logs and stored results', function () {
    $exception = new DestinationRejected(ProbeErrorCode::ForbiddenDestination, 'Port :port is not allowed on this instance.', ['port' => 8080]);

    expect($exception->getMessage())->toBe('Port 8080 is not allowed on this instance.')
        ->and($exception->translatedMessage('fr'))->toBe('Le port 8080 n’est pas autorisé sur cette instance.');
});

test('incident causes and timeline labels are shown in the organization language', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);

    foreach (['00', '01', '02'] as $minute) {
        recordCheck($monitor, false, "2026-11-22 10:{$minute}:00");
    }

    $incident = $monitor->incidents()->sole();

    $this->actingAs($this->owner)
        ->get(route('organizations.incidents.show', [$this->organization, $incident]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('incident.cause', 'Code HTTP inattendu (503)')
            ->where('incident.events.0.label', 'Incident ouvert'));
});
