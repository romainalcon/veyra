<?php

use App\Domain\Organizations\Enums\OrganizationRole;

test('capabilities follow the role hierarchy', function (OrganizationRole $role, bool $update, bool $delete, bool $members, bool $resources) {
    expect($role->canUpdateOrganization())->toBe($update)
        ->and($role->canDeleteOrganization())->toBe($delete)
        ->and($role->canManageMembers())->toBe($members)
        ->and($role->canManageResources())->toBe($resources);
})->with([
    'owner' => [OrganizationRole::Owner, true, true, true, true],
    'admin' => [OrganizationRole::Admin, true, false, true, true],
    'member' => [OrganizationRole::Member, false, false, false, true],
    'viewer' => [OrganizationRole::Viewer, false, false, false, false],
]);

test('only owners can assign the owner role', function () {
    expect(OrganizationRole::Owner->canAssign(OrganizationRole::Owner))->toBeTrue()
        ->and(OrganizationRole::Admin->canAssign(OrganizationRole::Owner))->toBeFalse()
        ->and(OrganizationRole::Admin->canAssign(OrganizationRole::Admin))->toBeTrue()
        ->and(OrganizationRole::Member->canAssign(OrganizationRole::Viewer))->toBeFalse();
});

test('assignable roles are derived from the actor role', function () {
    expect(OrganizationRole::Owner->assignableRoles())->toBe(OrganizationRole::cases())
        ->and(OrganizationRole::Admin->assignableRoles())->toBe([
            OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer,
        ])
        ->and(OrganizationRole::Viewer->assignableRoles())->toBe([]);
});
