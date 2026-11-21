<?php

namespace App\Domain\Organizations\DTOs;

use App\Domain\Organizations\Enums\OrganizationRole;
use Illuminate\Support\Str;

final readonly class InvitationData
{
    public function __construct(
        public string $email,
        public OrganizationRole $role,
    ) {}

    /**
     * @param  array{email: string, role: string}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            email: Str::lower(trim($validated['email'])),
            role: OrganizationRole::from($validated['role']),
        );
    }
}
