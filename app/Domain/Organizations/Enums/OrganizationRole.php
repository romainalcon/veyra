<?php

namespace App\Domain\Organizations\Enums;

/**
 * Fixed V1 organization roles, ordered from most to least privileged.
 *
 * Owner  — full control, including deleting the organization and managing owners.
 * Admin  — manages settings, members and invitations (but never owners).
 * Member — manages monitoring resources (introduced in later sprints).
 * Viewer — read-only access.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Higher values carry more privileges.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Member => 20,
            self::Viewer => 10,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function canUpdateOrganization(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    public function canDeleteOrganization(): bool
    {
        return $this === self::Owner;
    }

    public function canManageMembers(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    public function canManageResources(): bool
    {
        return $this->isAtLeast(self::Member);
    }

    /**
     * Whether a member with this role may grant, change or revoke the target role.
     */
    public function canAssign(self $target): bool
    {
        if (! $this->canManageMembers()) {
            return false;
        }

        return $this === self::Owner || $target !== self::Owner;
    }

    /**
     * Roles this role is allowed to assign.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $this->canAssign($role),
        ));
    }
}
