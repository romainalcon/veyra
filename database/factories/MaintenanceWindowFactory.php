<?php

namespace Database\Factories;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'starts_at' => now()->addHour()->startOfMinute(),
            'ends_at' => now()->addHours(2)->startOfMinute(),
        ];
    }

    /**
     * A window in effect right now.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMinutes(10)->startOfSecond(),
            'ends_at' => now()->addHour()->startOfSecond(),
        ]);
    }

    public function between(string $startsAt, string $endsAt): static
    {
        return $this->state(fn (): array => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
