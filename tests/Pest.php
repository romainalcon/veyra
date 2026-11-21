<?php

use App\Domain\Monitoring\Actions\ApplyMonitorState;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests run against MariaDB (database `veyra_testing`, see phpunit.xml).
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Integration tests commit real data to exercise MariaDB locking semantics.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/**
 * Create an organization with the given user attached under the given role.
 */
function organizationWith(User $user, OrganizationRole $role = OrganizationRole::Owner, array $attributes = []): Organization
{
    return Organization::factory()->withMember($user, $role)->create($attributes);
}

/**
 * Attach a fresh user to the organization with the given role.
 */
function memberOf(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create();

    $organization->members()->create([
        'user_id' => $user->id,
        'role' => $role,
        'joined_at' => now(),
    ]);

    return $user;
}

/**
 * Record a raw check for the monitor and apply it to the confirmed state,
 * as ExecuteHttpProbe + ApplyMonitorStateJob would.
 */
function recordCheck(Monitor $monitor, bool $success, string $scheduledFor, ?int $statusCode = null): ?MonitorStatus
{
    MonitorCheck::factory()->create([
        'monitor_id' => $monitor->id,
        'scheduled_for' => $scheduledFor,
        'checked_at' => CarbonImmutable::parse($scheduledFor)->addSeconds(1),
        'success' => $success,
        'status_code' => $statusCode ?? ($success ? 200 : 503),
        'error_code' => $success ? null : ProbeErrorCode::UnexpectedStatus,
    ]);

    return app(ApplyMonitorState::class)->handle($monitor->id);
}
