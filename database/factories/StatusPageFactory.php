<?php

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Models\StatusPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    protected $model = StatusPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'slug' => 'acme-'.Str::lower(Str::random(8)),
            'title' => 'Acme status',
            'description' => null,
            'is_published' => true,
            'show_uptime' => true,
        ];
    }

    public function withSubscriptions(): static
    {
        return $this->state(fn (): array => ['subscriptions_enabled' => true]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
