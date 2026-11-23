<?php

namespace App\Domain\Audit\Services;

/**
 * The resource an audit entry is about; the label is copied so the entry
 * stays readable after the resource is deleted.
 */
final readonly class AuditSubject
{
    public function __construct(
        public string $type,
        public ?string $id,
        public string $label,
    ) {}
}
