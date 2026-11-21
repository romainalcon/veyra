<?php

namespace App\Domain\Monitoring\Certificates;

use App\Domain\Monitoring\DTOs\TlsCertificate;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use Carbon\CarbonImmutable;

/**
 * Keeps the latest certificate of an HTTPS monitor. Writes only when the
 * certificate changes or the last confirmation is older than REFRESH_SECONDS,
 * so that frequent checks do not rewrite the row every minute.
 */
class RecordTlsCertificate
{
    public const REFRESH_SECONDS = 3600;

    public function handle(string $monitorId, TlsCertificate $certificate, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $current = MonitorTlsCertificate::query()->find($monitorId);

        if ($current !== null
            && $current->fingerprint === $certificate->fingerprint
            && $current->checked_at->greaterThan($now->subSeconds(self::REFRESH_SECONDS))) {
            return;
        }

        MonitorTlsCertificate::query()->updateOrCreate(['monitor_id' => $monitorId], [
            'fingerprint' => $certificate->fingerprint,
            'subject' => $certificate->subject,
            'issuer' => $certificate->issuer,
            'not_before' => $certificate->notBefore,
            'not_after' => $certificate->notAfter,
            'checked_at' => $now,
        ]);
    }
}
