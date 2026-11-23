<?php

namespace App\Domain\Monitoring\Transfer;

use App\Domain\Monitoring\DTOs\MonitorData;

/**
 * One entry of an import preview.
 */
final readonly class ImportRow
{
    /**
     * @param  list<string>  $errors  validation messages of an invalid entry
     * @param  list<string>  $excluded  secrets the export left out (headers, body, basic_auth, ping_token)
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $index,
        public string $name,
        public string $type,
        public ?string $target,
        public ImportRowStatus $status,
        public array $errors = [],
        public array $excluded = [],
        public ?MonitorData $data = null,
        public bool $enabled = true,
        public array $tags = [],
    ) {}

    /**
     * Browser representation (no validated payload).
     *
     * @return array{index: int, name: string, type: string, target: string|null, status: string, errors: list<string>, excluded: list<string>, enabled: bool, tags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->target,
            'status' => $this->status->value,
            'errors' => $this->errors,
            'excluded' => $this->excluded,
            'enabled' => $this->enabled,
            'tags' => $this->tags,
        ];
    }
}
