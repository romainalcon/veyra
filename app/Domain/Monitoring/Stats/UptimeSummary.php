<?php

namespace App\Domain\Monitoring\Stats;

/**
 * Uptime over observed checks. `uptime` is null when nothing was observed:
 * missing data is never reported as 100 % (nor 0 %).
 */
final readonly class UptimeSummary
{
    public const PARTIAL_COVERAGE_BELOW = 0.9;

    public function __construct(
        public int $checks,
        public int $successes,
        public int $expected,
    ) {}

    public function uptime(): ?float
    {
        return $this->checks === 0 ? null : round($this->successes / $this->checks * 100, 3);
    }

    public function coverage(): ?float
    {
        return $this->expected === 0 ? null : round(min(1, $this->checks / $this->expected), 3);
    }

    public function isPartial(): bool
    {
        $coverage = $this->coverage();

        return $coverage !== null && $coverage < self::PARTIAL_COVERAGE_BELOW;
    }

    /**
     * @return array{uptime: float|null, coverage: float|null, partial: bool, checks: int}
     */
    public function toArray(): array
    {
        return [
            'uptime' => $this->uptime(),
            'coverage' => $this->coverage(),
            'partial' => $this->isPartial(),
            'checks' => $this->checks,
        ];
    }
}
