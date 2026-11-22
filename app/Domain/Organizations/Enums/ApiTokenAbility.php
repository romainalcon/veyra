<?php

namespace App\Domain\Organizations\Enums;

/**
 * What an organization API token may do (ADR-044). A token acts as a
 * service account with a fixed organization role, so the existing policies
 * decide every request: `read` behaves as a Viewer, `write` as a Member.
 */
enum ApiTokenAbility: string
{
    case Read = 'read';
    case Write = 'write';

    public function role(): OrganizationRole
    {
        return match ($this) {
            self::Read => OrganizationRole::Viewer,
            self::Write => OrganizationRole::Member,
        };
    }

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Read => 'Read only',
            self::Write => 'Read and write',
        });
    }
}
