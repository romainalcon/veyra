<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, OrganizationRole::Owner);
});

function auditEntry(Organization $organization, AuditAction $action, array $attributes = []): AuditLog
{
    return AuditLog::query()->create([
        'organization_id' => $organization->id,
        'actor_type' => AuditActorType::System,
        'action' => $action,
        'created_at' => now(),
        ...$attributes,
    ]);
}

test('owners and admins read the audit log; members and viewers cannot', function (OrganizationRole $role, int $status) {
    auditEntry($this->organization, AuditAction::MonitorCreated);

    $this->actingAs(memberOf($this->organization, $role))
        ->get(route('organizations.audit-log.index', $this->organization))
        ->assertStatus($status);
})->with([
    'owner' => [OrganizationRole::Owner, 200],
    'admin' => [OrganizationRole::Admin, 200],
    'member' => [OrganizationRole::Member, 403],
    'viewer' => [OrganizationRole::Viewer, 403],
]);

test('the navigation shows the audit log to managers only', function () {
    $this->actingAs($this->owner)->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.can.viewAuditLog', true));

    $this->actingAs(memberOf($this->organization, OrganizationRole::Member))->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.can.viewAuditLog', false));
});

test('the log lists only the organization\'s entries, newest first', function () {
    $other = organizationWith(User::factory()->create());
    auditEntry($other, AuditAction::MonitorDeleted, ['subject_label' => 'Other org monitor']);
    auditEntry($this->organization, AuditAction::MonitorCreated, ['subject_type' => 'monitor', 'subject_label' => 'Old', 'created_at' => now()->subHour()]);
    auditEntry($this->organization, AuditAction::MemberInvited, ['subject_type' => 'invitation', 'subject_label' => 'new@example.com', 'actor_type' => AuditActorType::User, 'actor_name' => 'Olive']);

    $this->actingAs($this->owner)
        ->get(route('organizations.audit-log.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/AuditLog')
            ->has('entries.data', 2)
            ->where('entries.data.0.action', 'member.invited')
            ->where('entries.data.0.actor', ['type' => 'user', 'name' => 'Olive'])
            ->where('entries.data.0.subject.label', 'new@example.com')
            ->where('entries.data.1.subject.label', 'Old'));
});

test('entries can be filtered by resource; monitors include imports and exports', function () {
    auditEntry($this->organization, AuditAction::MonitorCreated);
    auditEntry($this->organization, AuditAction::MonitorsExported);
    auditEntry($this->organization, AuditAction::ApiTokenCreated);

    $this->actingAs($this->owner)
        ->get(route('organizations.audit-log.index', [$this->organization, 'category' => 'monitor']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)->where('category', 'monitor'));

    $this->get(route('organizations.audit-log.index', [$this->organization, 'category' => 'api_token']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.action', 'api_token.created'));

    $this->get(route('organizations.audit-log.index', [$this->organization, 'category' => 'nope']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 3)->where('category', null));
});

test('entries are append-only', function () {
    $entry = auditEntry($this->organization, AuditAction::MonitorCreated);

    expect(fn () => $entry->update(['subject_label' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->count())->toBe(1);
});

test('the retention purge deletes old entries only', function () {
    config(['veyra.audit.retention_days' => 30]);
    auditEntry($this->organization, AuditAction::MonitorCreated, ['created_at' => now()->subDays(31)]);
    $kept = auditEntry($this->organization, AuditAction::MonitorDeleted, ['created_at' => now()->subDays(29)]);

    (new PurgeExpiredHistory)->handle();

    expect(AuditLog::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('entries are removed with their organization', function () {
    auditEntry($this->organization, AuditAction::MonitorCreated);

    $this->organization->delete();

    expect(AuditLog::query()->count())->toBe(0);
});
