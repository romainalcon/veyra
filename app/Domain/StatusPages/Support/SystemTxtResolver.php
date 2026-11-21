<?php

namespace App\Domain\StatusPages\Support;

use App\Domain\StatusPages\Contracts\TxtResolver;

/**
 * TXT lookups through the system resolver (used only to verify domain
 * ownership; never to decide where a request goes).
 */
class SystemTxtResolver implements TxtResolver
{
    public function lookup(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            $value = isset($record['entries']) && is_array($record['entries'])
                ? implode('', array_map('strval', $record['entries']))
                : (string) ($record['txt'] ?? '');

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
