<?php

namespace App\Domain\Organizations\DTOs;

final readonly class OrganizationData
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * @param  array{name: string}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(name: trim($validated['name']));
    }
}
