<?php

namespace App\Domain\Monitoring\Certificates;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Notifications\Actions\QueueCertificateNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raises expiry warnings for the certificates seen by HTTPS monitors
 * (ADR-026). Only the most urgent threshold reached is raised, once per
 * certificate: a certificate first seen 5 days before expiry gets the 7-day
 * warning, not the 14-day one too. A renewed certificate has a new
 * fingerprint and starts over.
 */
class CheckCertificateExpiry
{
    public function __construct(private readonly QueueCertificateNotifications $queueCertificateNotifications) {}

    /**
     * @return int alerts raised
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $thresholds = self::thresholds();
        $raised = 0;

        MonitorTlsCertificate::query()
            ->where('not_after', '<=', $now->addDays(max($thresholds)))
            ->whereHas('monitor', fn ($query) => $query
                ->where('enabled', true)
                ->whereNull('deleted_at')
                ->where('type', MonitorType::Http->value))
            ->with('monitor:id,organization_id')
            ->chunkById(200, function ($certificates) use ($now, $thresholds, &$raised): void {
                foreach ($certificates as $certificate) {
                    $threshold = self::thresholdFor($certificate->not_after, $now, $thresholds);

                    if ($threshold !== null && $this->raise($certificate, $threshold, $now)) {
                        $raised++;
                    }
                }
            }, 'monitor_id');

        return $raised;
    }

    /**
     * Configured warning thresholds in days, descending (e.g. [14, 7, 1]).
     *
     * @return non-empty-list<int>
     */
    public static function thresholds(): array
    {
        /** @var array<int, int|string> $configured */
        $configured = (array) config('veyra.certificates.warning_days', [14, 7, 1]);
        $days = array_values(array_unique(array_filter(array_map('intval', $configured), fn (int $day): bool => $day > 0 && $day <= 90)));
        rsort($days);

        return $days === [] ? [14, 7, 1] : $days;
    }

    /**
     * 0 when expired, else the smallest threshold whose window has been reached.
     *
     * @param  non-empty-list<int>  $thresholds  descending
     */
    public static function thresholdFor(CarbonImmutable $notAfter, CarbonImmutable $now, array $thresholds): ?int
    {
        if ($notAfter->lessThanOrEqualTo($now)) {
            return 0;
        }

        $reached = null;

        foreach ($thresholds as $days) {
            if ($notAfter->lessThanOrEqualTo($now->addDays($days))) {
                $reached = $days;
            }
        }

        return $reached;
    }

    private function raise(MonitorTlsCertificate $certificate, int $threshold, CarbonImmutable $now): bool
    {
        /** @var Monitor $monitor */
        $monitor = $certificate->monitor;

        return DB::transaction(function () use ($certificate, $monitor, $threshold, $now): bool {
            $id = (string) Str::uuid7();

            $inserted = DB::table('tls_certificate_alerts')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $monitor->organization_id,
                'monitor_id' => $monitor->id,
                'fingerprint' => $certificate->fingerprint,
                'threshold_days' => $threshold,
                'not_after' => $certificate->not_after->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
            ]);

            if ($inserted === 1) {
                $this->queueCertificateNotifications->handle($id);
            }

            return $inserted === 1;
        });
    }
}
