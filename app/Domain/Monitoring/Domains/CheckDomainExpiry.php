<?php

namespace App\Domain\Monitoring\Domains;

use App\Domain\Monitoring\Certificates\CheckCertificateExpiry;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\MonitorDomain;
use App\Domain\Notifications\Actions\QueueDomainNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raises domain expiry warnings (ADR-043) at the certificate thresholds
 * (ADR-026): only the most urgent threshold reached, once per domain and
 * expiry date. A renewal moves the expiry date and starts over. Rows
 * computed for a host the monitor no longer follows are ignored.
 */
class CheckDomainExpiry
{
    public function __construct(private readonly QueueDomainNotifications $queueDomainNotifications) {}

    /**
     * @return int alerts raised
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $thresholds = CheckCertificateExpiry::thresholds();
        $raised = 0;

        MonitorDomain::query()
            ->whereNotNull('expires_at')
            ->whereNotNull('domain')
            ->where('expires_at', '<=', $now->addDays(max($thresholds)))
            ->whereHas('monitor', fn ($query) => $query
                ->where('enabled', true)
                ->whereNull('deleted_at')
                ->whereIn('type', MonitorType::withDomainExpiry()))
            ->with(['monitor.httpConfig', 'monitor.dnsConfig'])
            ->chunkById(200, function ($rows) use ($now, $thresholds, &$raised): void {
                foreach ($rows as $row) {
                    if ($row->expires_at === null || $row->monitor->domainHost() !== $row->host) {
                        continue;
                    }

                    $threshold = CheckCertificateExpiry::thresholdFor($row->expires_at, $now, $thresholds);

                    if ($threshold !== null && $this->raise($row, $threshold, $now)) {
                        $raised++;
                    }
                }
            }, 'monitor_id');

        return $raised;
    }

    private function raise(MonitorDomain $row, int $threshold, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($row, $threshold, $now): bool {
            $id = (string) Str::uuid7();

            $inserted = DB::table('domain_expiry_alerts')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $row->monitor->organization_id,
                'monitor_id' => $row->monitor_id,
                'domain' => $row->domain,
                'threshold_days' => $threshold,
                'expires_at' => $row->expires_at?->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
            ]);

            if ($inserted === 1) {
                $this->queueDomainNotifications->handle($id);
            }

            return $inserted === 1;
        });
    }
}
