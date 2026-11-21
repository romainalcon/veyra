<?php

namespace Database\Factories;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->domainWord().' website',
            'type' => MonitorType::Http,
            'enabled' => true,
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'next_check_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Monitor $monitor): void {
            $monitor->httpConfig()->create([
                'url' => 'https://example.com/health',
                'method' => HttpMethod::Get,
                'expected_statuses' => ExpectedStatuses::default(),
                'follow_redirects' => true,
                'headers_encrypted' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function http(array $attributes): static
    {
        return $this->afterCreating(function (Monitor $monitor) use ($attributes): void {
            $monitor->httpConfig()->update($attributes);
        });
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => ['next_check_at' => now()->subSecond()]);
    }
}
