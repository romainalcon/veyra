<?php

namespace Database\Factories;

use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceSeries>
 */
class MaintenanceSeriesFactory extends Factory
{
    protected $model = MaintenanceSeries::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'frequency' => MaintenanceFrequency::Daily,
            'weekdays' => null,
            'month_day' => null,
            'start_time' => '02:00:00',
            'duration_minutes' => 60,
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
        ];
    }

    /**
     * @param  list<int>  $weekdays
     */
    public function weekly(array $weekdays): static
    {
        return $this->state(fn (): array => ['frequency' => MaintenanceFrequency::Weekly, 'weekdays' => $weekdays]);
    }

    public function monthly(int $day): static
    {
        return $this->state(fn (): array => ['frequency' => MaintenanceFrequency::Monthly, 'month_day' => $day]);
    }
}
