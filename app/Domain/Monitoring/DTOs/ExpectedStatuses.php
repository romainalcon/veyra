<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Casts\ExpectedStatusesCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;

/**
 * A set of accepted HTTP status ranges, e.g. "200-299, 301".
 */
final readonly class ExpectedStatuses implements Castable
{
    public const MAX_RANGES = 20;

    /**
     * @param  list<array{from: int, to: int}>  $ranges
     */
    private function __construct(public array $ranges) {}

    public static function default(): self
    {
        return new self([['from' => 200, 'to' => 299]]);
    }

    /**
     * Parse a user-supplied expression such as "200-299, 301".
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $expression): self
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $expression)), fn (string $part): bool => $part !== ''));

        if ($parts === [] || count($parts) > self::MAX_RANGES) {
            throw new InvalidArgumentException('Provide between 1 and '.self::MAX_RANGES.' status codes or ranges.');
        }

        $ranges = [];

        foreach ($parts as $part) {
            if (preg_match('/^(\d{3})(?:\s*-\s*(\d{3}))?$/', $part, $matches) !== 1) {
                throw new InvalidArgumentException("Invalid status code or range: {$part}");
            }

            $from = (int) $matches[1];
            $to = isset($matches[2]) ? (int) $matches[2] : $from;

            $ranges[] = ['from' => $from, 'to' => $to];
        }

        return self::fromArray($ranges);
    }

    /**
     * @param  array<int, mixed>  $ranges
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $ranges): self
    {
        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || ! isset($range['from'], $range['to'])) {
                throw new InvalidArgumentException('Invalid status range.');
            }

            $from = (int) $range['from'];
            $to = (int) $range['to'];

            if ($from < 100 || $to > 599 || $from > $to) {
                throw new InvalidArgumentException("Status ranges must be between 100 and 599: {$from}-{$to}");
            }

            $normalized[] = ['from' => $from, 'to' => $to];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one status range is required.');
        }

        usort($normalized, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return new self($normalized);
    }

    public function contains(int $status): bool
    {
        foreach ($this->ranges as $range) {
            if ($status >= $range['from'] && $status <= $range['to']) {
                return true;
            }
        }

        return false;
    }

    public function toExpression(): string
    {
        return implode(', ', array_map(
            fn (array $range): string => $range['from'] === $range['to'] ? (string) $range['from'] : "{$range['from']}-{$range['to']}",
            $this->ranges,
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return class-string<ExpectedStatusesCast>
     */
    public static function castUsing(array $arguments): string
    {
        return ExpectedStatusesCast::class;
    }
}
