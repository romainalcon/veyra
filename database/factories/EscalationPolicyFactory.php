<?php

namespace Database\Factories;

use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationPolicy>
 */
class EscalationPolicyFactory extends Factory
{
    protected $model = EscalationPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'On-call escalation',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * Adds steps after creation: each entry is a delay in minutes and the
     * channels of that step.
     *
     * @param  list<array{int, list<NotificationChannel>}>  $steps
     */
    public function withSteps(array $steps): static
    {
        return $this->afterCreating(function (EscalationPolicy $policy) use ($steps): void {
            foreach ($steps as $index => [$delay, $channels]) {
                $step = $policy->steps()->create(['position' => $index + 1, 'delay_minutes' => $delay]);
                $step->channels()->sync(array_map(fn (NotificationChannel $channel): string => $channel->id, $channels));
            }
        });
    }
}
