<?php

namespace Database\Factories;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ];
    }

    /**
     * Attach the given user with the given role once the organization is created.
     */
    public function withMember(User $user, OrganizationRole $role = OrganizationRole::Owner): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role): void {
            $organization->members()->create([
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        });
    }
}
