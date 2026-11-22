<?php

namespace App\Domain\Organizations\DTOs;

final readonly class OrganizationData
{
    /**
     * @param  string|null  $locale  A supported locale; null keeps the current one (or the request language on creation).
     * @param  string|null  $timezone  An IANA time zone; null keeps the current one (UTC on creation).
     */
    public function __construct(
        public string $name,
        public ?string $locale = null,
        public ?string $timezone = null,
    ) {}

    /**
     * @param  array{name: string, locale?: string|null, timezone?: string|null}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim($validated['name']),
            locale: $validated['locale'] ?? null,
            timezone: $validated['timezone'] ?? null,
        );
    }
}
