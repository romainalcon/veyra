<?php

namespace App\Domain\Monitoring\Transfer;

/**
 * Result of an import dry run.
 */
final readonly class ImportPreview
{
    /**
     * @param  list<ImportRow>  $rows
     * @param  array{used: int, limit: int}|null  $quota  active monitor quota; null when unlimited
     */
    public function __construct(
        public array $rows,
        public ?array $quota,
    ) {}

    /**
     * @return list<ImportRow>
     */
    public function toCreate(): array
    {
        return array_values(array_filter($this->rows, fn (ImportRow $row): bool => $row->status === ImportRowStatus::Valid));
    }

    public function invalidCount(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->status === ImportRowStatus::Invalid));
    }

    public function duplicateCount(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->status === ImportRowStatus::Duplicate));
    }

    /**
     * Monitors to create, paused ones first: a monitor is created active and
     * then paused, so a paused one needs a free slot only for a moment.
     *
     * @return list<ImportRow>
     */
    public function creationOrder(): array
    {
        $rows = $this->toCreate();

        return [
            ...array_filter($rows, fn (ImportRow $row): bool => ! $row->enabled),
            ...array_filter($rows, fn (ImportRow $row): bool => $row->enabled),
        ];
    }

    /**
     * Whether the monitors to create fit the active monitor quota (see creationOrder()).
     */
    public function fitsQuota(): bool
    {
        if ($this->quota === null) {
            return true;
        }

        $rows = $this->toCreate();
        $active = count(array_filter($rows, fn (ImportRow $row): bool => $row->enabled));
        $needed = max($active, $active < count($rows) ? 1 : 0);

        return $this->quota['limit'] >= $this->quota['used'] + $needed;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, valid: int, duplicates: int, invalid: int}, quota: array{used: int, limit: int}|null, fitsQuota: bool}
     */
    public function toArray(): array
    {
        return [
            'rows' => array_map(fn (ImportRow $row): array => $row->toArray(), $this->rows),
            'summary' => [
                'total' => count($this->rows),
                'valid' => count($this->toCreate()),
                'duplicates' => $this->duplicateCount(),
                'invalid' => $this->invalidCount(),
            ],
            'quota' => $this->quota,
            'fitsQuota' => $this->fitsQuota(),
        ];
    }
}
