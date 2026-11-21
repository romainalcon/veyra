<?php

namespace Database\Factories;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'organization_id' => fn (array $attributes): string => Monitor::withTrashed()->findOrFail((string) $attributes['monitor_id'])->organization_id,
            'status' => IncidentStatus::Open,
            'started_at' => now()->subMinutes(5),
            'cause' => 'Unexpected status code (503)',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
