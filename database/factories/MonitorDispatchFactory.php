<?php

namespace Database\Factories;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorDispatch>
 */
class MonitorDispatchFactory extends Factory
{
    protected $model = MonitorDispatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'scheduled_for' => now()->startOfSecond(),
            'trigger' => DispatchTrigger::Scheduled,
            'status' => DispatchStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function status(DispatchStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
