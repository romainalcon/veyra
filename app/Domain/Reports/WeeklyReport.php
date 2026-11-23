<?php

namespace App\Domain\Reports;

/**
 * Content of one organization's weekly report: plain values only, so the
 * queued e-mail serializes no model. Times are already formatted in the
 * organization time zone; uptime is null when nothing was observed.
 */
final readonly class WeeklyReport
{
    /**
     * @param  array{uptime: float|null, coverage: float|null, partial: bool, checks: int}  $overall
     * @param  list<array{name: string, uptime: float|null, coverage: float|null, partial: bool}>  $lowestUptime
     * @param  list<array{name: string, latency_ms: int}>  $slowest
     * @param  list<array{monitor: string, started_at: string, duration: string, open: bool}>  $incidents
     */
    public function __construct(
        public string $organizationName,
        public string $organizationSlug,
        public string $locale,
        public string $timezone,
        public string $periodLabel,
        public int $monitorsCount,
        public array $overall,
        public array $lowestUptime,
        public array $slowest,
        public int $incidentsCount,
        public int $downtimeSeconds,
        public array $incidents,
    ) {}
}
