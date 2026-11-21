<?php

namespace App\Domain\StatusPages\Contracts;

interface TxtResolver
{
    /**
     * TXT records of the name (empty when it does not resolve).
     *
     * @return list<string>
     */
    public function lookup(string $name): array;
}
