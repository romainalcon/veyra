<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;

final readonly class DnsTargetData
{
    /**
     * @param  list<string>  $expectedValues  normalized (DnsAnswerMatcher), empty for "any answer"
     */
    public function __construct(
        public string $recordName,
        public DnsRecordType $recordType,
        public DnsResolverChoice $resolver,
        public array $expectedValues,
        public DnsMatchMode $matchMode,
    ) {}

    /**
     * @return array{record_name: string, record_type: DnsRecordType, resolver: DnsResolverChoice, expected_values: list<string>|null, match_mode: DnsMatchMode}
     */
    public function toConfig(): array
    {
        return [
            'record_name' => $this->recordName,
            'record_type' => $this->recordType,
            'resolver' => $this->resolver,
            'expected_values' => $this->expectedValues === [] ? null : $this->expectedValues,
            'match_mode' => $this->matchMode,
        ];
    }
}
