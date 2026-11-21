<?php

namespace App\Domain\StatusPages\DTOs;

final readonly class StatusPageData
{
    /**
     * @param  list<array{monitor_id: string, display_name: string, group_name: string|null}>  $components  in display order
     */
    public function __construct(
        public string $title,
        public string $slug,
        public ?string $description,
        public bool $isPublished,
        public bool $showUptime,
        public array $components,
    ) {}
}
