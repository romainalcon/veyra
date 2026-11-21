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
}
