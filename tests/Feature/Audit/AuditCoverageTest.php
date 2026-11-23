<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\StatusPages\Actions\VerifyStatusPageDomain;
use App\Domain\StatusPages\Contracts\TxtResolver;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Support\DomainName;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TestImages;

/*
 * ADR-046: every audited action is produced through the interface (or the
 * API), with the expected actor and subject. A new AuditAction case that no
 * scenario produces makes the coverage test fail.
 */

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Olive Owner']);
    $this->organization = organizationWith($this->owner, OrganizationRole::Owner);
});

function auditHttpMonitor(array $overrides = []): array
{
    return [
        'type' => 'http',
        'name' => 'Website',
        'url' => 'https://example.com/health?token=query-secret',
        'method' => 'POST',
        'interval' => 60,
        'timeout' => 10,
        'expected_statuses' => '200-299',
        'follow_redirects' => true,
        'headers' => [['name' => 'X-Api-Key', 'value' => 'header-secret-value']],
        'body' => '{"password":"body-secret-value"}',
        'body_content_type' => 'application/json',
        'basic_auth_username' => 'probe',
        'basic_auth_password' => 'basic-secret-value',
        'tags_submitted' => '1',
        'tags' => ['Production'],
        ...$overrides,
    ];
}

/**
 * Performs every audited action once through the interface.
 */
function performEveryAuditedAction(object $test): void
{
    $organization = $test->organization;
    $test->actingAs($test->owner);

    $test->patch(route('organizations.update', $organization), ['name' => 'Acme Corp', 'locale' => 'en', 'timezone' => 'Europe/Paris'])->assertSessionHasNoErrors();
    $test->put(route('organizations.two-factor.update', $organization), ['require_two_factor' => '1'])->assertSessionHasNoErrors();

    // Members and invitations.
    $test->post(route('organizations.invitations.store', $organization), ['email' => 'revoked@example.com', 'role' => 'viewer'])->assertSessionHasNoErrors();
    $test->delete(route('organizations.invitations.destroy', [$organization, OrganizationInvitation::query()->sole()]))->assertSessionHasNoErrors();

    $invitee = User::factory()->withTwoFactor()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->withToken('audit-token')->create(['organization_id' => $organization->id, 'email' => 'invitee@example.com', 'role' => OrganizationRole::Member]);
    $test->actingAs($invitee)->post(route('invitations.accept', 'audit-token'))->assertSessionHasNoErrors();
    $inviteeMember = $organization->members()->where('user_id', $invitee->id)->sole();

    $leaver = memberOf($organization, OrganizationRole::Viewer);
    $test->actingAs($leaver)->delete(route('organizations.members.destroy', [$organization, $organization->members()->where('user_id', $leaver->id)->sole()]));

    $test->actingAs($test->owner);
    $test->patch(route('organizations.members.update', [$organization, $inviteeMember]), ['role' => 'admin'])->assertSessionHasNoErrors();
    $test->delete(route('organizations.members.destroy', [$organization, $inviteeMember]))->assertSessionHasNoErrors();

    // API tokens.
    $test->post(route('organizations.api-tokens.store', $organization), ['name' => 'Pipeline', 'ability' => 'write', 'expires_in_days' => '90'])->assertSessionHasNoErrors();
    $token = ApiToken::query()->sole();
    $test->delete(route('organizations.api-tokens.destroy', [$organization, $token]))->assertSessionHasNoErrors();

    // Monitors.
    $test->post(route('organizations.monitors.store', $organization), auditHttpMonitor())->assertSessionHasNoErrors();
    $monitor = Monitor::query()->sole();
    $test->put(route('organizations.monitors.update', [$organization, $monitor]), auditHttpMonitor([
        'name' => 'Website (prod)', 'headers_mode' => 'keep', 'body_mode' => 'keep', 'auth_mode' => 'keep', 'tags' => ['production', 'web'],
    ]))->assertSessionHasNoErrors();
    $test->patch(route('organizations.monitors.enabled', [$organization, $monitor]), ['enabled' => false])->assertSessionHasNoErrors();
    $test->patch(route('organizations.monitors.enabled', [$organization, $monitor]), ['enabled' => true])->assertSessionHasNoErrors();
    $test->post(route('organizations.monitors.store', $organization), ['type' => 'heartbeat', 'name' => 'Backups', 'interval' => 3600, 'grace_minutes' => 10])->assertSessionHasNoErrors();
    $heartbeat = Monitor::query()->where('name', 'Backups')->sole();
    $test->post(route('organizations.monitors.ping-token.store', [$organization, $heartbeat]))->assertSessionHasNoErrors();
    $test->get(route('organizations.monitors.export', $organization))->assertOk();
    $test->post(route('organizations.monitors.import.store', $organization), [
        'file' => UploadedFile::fake()->createWithContent('monitors.json', json_encode([
            'format' => 'veyra.monitors', 'version' => 1,
            'monitors' => [['name' => 'Imported', 'type' => 'tcp', 'interval_seconds' => 60, 'timeout_seconds' => 5, 'tcp' => ['host' => 'example.com', 'port' => 443]]],
        ])),
    ])->assertSessionHasNoErrors();
    $test->delete(route('organizations.monitors.destroy', [$organization, $heartbeat]))->assertSessionHasNoErrors();

    // Notification channels and escalation policies.
    $test->post(route('organizations.notification-channels.store', $organization), ['type' => 'webhook', 'name' => 'Hook', 'webhook_url' => 'https://example.com/hooks/abc?sig=channel-secret'])->assertSessionHasNoErrors();
    $channel = NotificationChannel::query()->sole();
    $test->put(route('organizations.notification-channels.update', [$organization, $channel]), ['name' => 'Hook 2', 'enabled' => '1', 'webhook_url' => 'https://example.com/hooks/def?sig=other-secret'])->assertSessionHasNoErrors();
    $test->post(route('organizations.notification-channels.signing-secret.store', [$organization, $channel]))->assertSessionHasNoErrors();
    $test->post(route('organizations.escalation-policies.store', $organization), ['name' => 'Night', 'is_default' => '1', 'steps' => [['delay_minutes' => 10, 'channel_ids' => [$channel->id]]]])->assertSessionHasNoErrors();
    $policy = EscalationPolicy::query()->sole();
    $test->put(route('organizations.escalation-policies.update', [$organization, $policy]), ['name' => 'Night shift', 'is_default' => '1', 'steps' => [['delay_minutes' => 15, 'channel_ids' => [$channel->id]]]])->assertSessionHasNoErrors();
    $test->delete(route('organizations.escalation-policies.destroy', [$organization, $policy]))->assertSessionHasNoErrors();
    $test->delete(route('organizations.notification-channels.destroy', [$organization, $channel]))->assertSessionHasNoErrors();

    // Status pages.
    $test->post(route('organizations.status-pages.store', $organization), ['title' => 'Acme status', 'slug' => 'acme', 'description' => '', 'is_published' => '1', 'show_uptime' => '1'])->assertSessionHasNoErrors();
    $page = StatusPage::query()->sole();
    $test->put(route('organizations.status-pages.update', [$organization, $page]), ['title' => 'Acme', 'slug' => 'acme', 'description' => 'All systems', 'is_published' => '1', 'show_uptime' => '0'])->assertSessionHasNoErrors();
    $test->put(route('organizations.status-pages.domain.update', [$organization, $page]), ['custom_domain' => 'status.acme.test'])->assertSessionHasNoErrors();
    app(TxtResolver::class)->set(DomainName::challengeName('status.acme.test'), [DomainName::challengeValue((string) $page->fresh()->domain_verification_token)]);
    $test->post(route('organizations.status-pages.domain.verify', [$organization, $page]))->assertSessionHasNoErrors();
    $test->post(route('organizations.status-pages.assets.store', [$organization, $page, 'logo']), ['file' => UploadedFile::fake()->createWithContent('logo.png', TestImages::png())])->assertSessionHasNoErrors();
    $test->delete(route('organizations.status-pages.assets.destroy', [$organization, $page, 'logo']))->assertSessionHasNoErrors();
    $test->delete(route('organizations.status-pages.destroy', [$organization, $page]))->assertSessionHasNoErrors();
}

test('every audited action is recorded', function () {
    performEveryAuditedAction($this);

    // Organization creation is recorded by the creating action (organizationWith uses factories).
    $this->post(route('organizations.store'), ['name' => 'Second org'])->assertRedirect();

    $recorded = AuditLog::query()->pluck('action')->map(fn (AuditAction $action): string => $action->value)->unique()->sort()->values()->all();
    $expected = collect(AuditAction::cases())->map(fn (AuditAction $action): string => $action->value)->sort()->values()->all();

    expect($recorded)->toBe($expected);
});

test('entries name the actor and the subject, and never contain secrets', function () {
    performEveryAuditedAction($this);

    $entries = AuditLog::query()->where('organization_id', $this->organization->id)->get();
    $raw = json_encode(DB::table('audit_logs')->get());

    foreach (['header-secret-value', 'body-secret-value', 'basic-secret-value', 'query-secret', 'channel-secret', 'other-secret', 'whsec_', 'audit-token', 'hooks/abc', 'hooks/def'] as $secret) {
        expect($raw)->not->toContain($secret);
    }

    $created = $entries->firstWhere('action', AuditAction::MonitorCreated);
    expect($created->actor_type)->toBe(AuditActorType::User)
        ->and($created->actor_name)->toBe('Olive Owner')
        ->and($created->subject_type)->toBe('monitor')
        ->and($created->subject_label)->toBe('Website')
        ->and($created->changes['after'])->toMatchArray([
            'url' => 'https://example.com/health',
            'url_has_query' => true,
            'header_names' => ['X-Api-Key'],
            'body_set' => true,
            'basic_auth_set' => true,
            'tags' => ['production'],
        ]);

    $updated = $entries->firstWhere('action', AuditAction::MonitorUpdated);
    expect($updated->changes)->toBe([
        'before' => ['name' => 'Website', 'tags' => ['production']],
        'after' => ['name' => 'Website (prod)', 'tags' => ['production', 'web']],
    ]);

    $channelUpdate = $entries->firstWhere('action', AuditAction::NotificationChannelUpdated);
    expect($channelUpdate->metadata)->toBe(['replaced_secrets' => ['webhook_url']])
        ->and($channelUpdate->changes)->toBe(['before' => ['name' => 'Hook'], 'after' => ['name' => 'Hook 2']]);

    expect($entries->firstWhere('action', AuditAction::MemberLeft)->actor_type)->toBe(AuditActorType::User)
        ->and($entries->firstWhere('action', AuditAction::MemberRoleChanged)->changes)->toBe(['before' => ['role' => 'member'], 'after' => ['role' => 'admin']])
        ->and($entries->firstWhere('action', AuditAction::ApiTokenCreated)->changes['after']['token'])->toStartWith('veyra_…')
        ->and($entries->firstWhere('action', AuditAction::MonitorsImported)->metadata)->toBe(['created' => 1, 'skipped' => 0])
        ->and($entries->where('action', AuditAction::MonitorCreated)->last()->metadata)->toBe(['source' => 'import']);
});

test('changing nothing records nothing', function () {
    $this->actingAs($this->owner)->post(route('organizations.status-pages.store', $this->organization), ['title' => 'Acme', 'slug' => 'acme', 'description' => '', 'is_published' => '1', 'show_uptime' => '1']);
    $page = StatusPage::query()->sole();

    $this->put(route('organizations.status-pages.update', [$this->organization, $page]), ['title' => 'Acme', 'slug' => 'acme', 'description' => '', 'is_published' => '1', 'show_uptime' => '1'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::StatusPageUpdated)->exists())->toBeFalse();
});

test('API tokens are recorded as the actor of API changes', function () {
    $token = apiTokenFor($this->organization, attributes: ['name' => 'Terraform']);

    $this->withApiToken($token)->postJson(route('api.v1.monitors.store'), [
        'type' => 'tcp', 'name' => 'DB', 'host' => 'example.com', 'port' => 443, 'interval' => 60, 'timeout' => 5, 'tags' => ['db'],
    ])->assertCreated();

    $entry = AuditLog::query()->where('action', AuditAction::MonitorCreated)->sole();
    expect($entry->actor_type)->toBe(AuditActorType::ApiToken)
        ->and($entry->actor_name)->toBe('Terraform')
        ->and($entry->actor_id)->toBe(ApiToken::query()->sole()->id);
});

test('scheduled jobs are recorded as the system', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'custom_domain' => 'status.acme.test', 'domain_verification_token' => str_repeat('a', 40)]);
    app(TxtResolver::class)->set(DomainName::challengeName('status.acme.test'), [DomainName::challengeValue(str_repeat('a', 40))]);

    app(VerifyStatusPageDomain::class)->handle($page);

    $entry = AuditLog::query()->sole();
    expect($entry->action)->toBe(AuditAction::StatusPageDomainVerified)
        ->and($entry->actor_type)->toBe(AuditActorType::System)
        ->and($entry->actor_id)->toBeNull();
});
