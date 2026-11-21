<?php

namespace App\Domain\Organizations\DTOs;

final readonly class OrganizationData
{
    /**
     * @param  string|null  $locale  A supported locale; null keeps the current one (or the request language on creation).
     */
    public function __construct(
        public string $name,
        public ?string $locale = null,
    ) {}

    /**
     * @param  array{name: string, locale?: string|null}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim($validated['name']),
            locale: $validated['locale'] ?? null,
        );
    }
}
