<?php

namespace App\Domain\System\Benchmark;

use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\MonitorHttpConfig;
use App\Domain\Monitoring\Probes\HttpProbe;

/**
 * Benchmark-only probe: no network access, configurable latency and failure
 * rate. It is bound only when VEYRA_BENCHMARK_PROBE is set outside
 * production (see AppServiceProvider), so the SSRF policy is untouched.
 */
class SimulatedProbe extends HttpProbe
{
    public function __construct(
        private readonly int $latencyMs,
        private readonly float $failureRate,
    ) {}

    /**
     * Parses "latency_ms:failure_rate", e.g. "120:0.05".
     */
    public static function fromSpec(string $spec): self
    {
        [$latency, $failure] = array_pad(explode(':', $spec), 2, '0');

        return new self(max(0, (int) $latency), min(1.0, max(0.0, (float) $failure)));
    }

    public function run(MonitorHttpConfig $config, int $timeoutSeconds): ProbeResult
    {
        $jitter = (int) round($this->latencyMs * (mt_rand(80, 120) / 100));
        usleep($jitter * 1000);

        if (mt_rand() / mt_getrandmax() < $this->failureRate) {
            return ProbeResult::failure(ProbeErrorCode::UnexpectedStatus, $jitter, 503, ['redirects' => 0, 'simulated' => true]);
        }

        return new ProbeResult(true, $jitter, 200, null, ['redirects' => 0, 'simulated' => true]);
    }
}
