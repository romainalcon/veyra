<?php

namespace Database\Factories;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitorCheck>
 */
class MonitorCheckFactory extends Factory
{
    protected $model = MonitorCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'dispatch_id' => (string) Str::uuid7(),
            'scheduled_for' => now()->startOfSecond(),
            'checked_at' => now(),
            'success' => true,
            'duration_ms' => fake()->numberBetween(50, 800),
            'status_code' => 200,
            'error_code' => null,
            'metadata' => ['redirects' => 0],
        ];
    }

    /**
     * A successful HTTPS check with every phase measured.
     */
    public function withTimings(int $dns = 12, int $connect = 25, int $tls = 40, int $ttfb = 110, int $transfer = 8): static
    {
        return $this->state(fn (): array => [
            'dns_ms' => $dns,
            'connect_ms' => $connect,
            'tls_ms' => $tls,
            'ttfb_ms' => $ttfb,
            'transfer_ms' => $transfer,
            'redirect_ms' => 0,
            'duration_ms' => $dns + $connect + $tls + $ttfb + $transfer,
        ]);
    }
}
