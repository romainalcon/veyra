<?php

namespace App\Domain\Monitoring\Casts;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<ExpectedStatuses, ExpectedStatuses>
 */
class ExpectedStatusesCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ExpectedStatuses
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? ExpectedStatuses::fromArray($decoded) : ExpectedStatuses::default();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! $value instanceof ExpectedStatuses) {
            throw new InvalidArgumentException('Expected an ExpectedStatuses instance.');
        }

        return (string) json_encode($value->ranges);
    }
}
