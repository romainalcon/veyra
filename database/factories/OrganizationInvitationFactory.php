<?php

namespace Database\Factories;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member,
            'token_hash' => OrganizationInvitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Use a known plain token so tests can exercise the acceptance flow.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn (): array => ['token_hash' => OrganizationInvitation::hashToken($plainToken)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
