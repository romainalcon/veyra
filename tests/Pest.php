<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
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
