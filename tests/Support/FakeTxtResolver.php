<?php

namespace Tests\Support;

use App\Domain\StatusPages\Contracts\TxtResolver;

/**
 * Deterministic TXT records for tests: no real network lookups.
 */
class FakeTxtResolver implements TxtResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(public array $records = []) {}

    public function lookup(string $name): array
    {
        return $this->records[$name] ?? [];
    }

    /**
     * @param  list<string>  $values
     */
    public function set(string $name, array $values): self
    {
        $this->records[$name] = $values;

        return $this;
    }
}
