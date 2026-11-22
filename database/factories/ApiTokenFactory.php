<?php

namespace Database\Factories;

use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Support\ApiTokenSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factories cannot return the plain token: tests that call the API use
 * `ApiTokenFactory::issue()`, which keeps it.
 *
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = ApiTokenSecret::generate();

        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true),
            'ability' => ApiTokenAbility::Write,
            'token_hash' => ApiTokenSecret::hash($plain),
            'last_four' => substr($plain, -4),
        ];
    }

    public function readOnly(): static
    {
        return $this->state(fn (): array => ['ability' => ApiTokenAbility::Read]);
    }

    /**
     * Create a token and return it with its plain value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ApiToken, 1: string}
     */
    public function issue(array $attributes = []): array
    {
        $plain = ApiTokenSecret::generate();

        $token = $this->createOne([
            ...$attributes,
            'token_hash' => ApiTokenSecret::hash($plain),
            'last_four' => substr($plain, -4),
        ]);

        return [$token, $plain];
    }
}
