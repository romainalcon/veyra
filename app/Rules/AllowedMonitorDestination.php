<?php

namespace App\Rules;

use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\DestinationRejected;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Early feedback when saving a monitor. The probe re-validates the destination
 * (and every redirect) at execution time, since DNS answers can change.
 */
class AllowedMonitorDestination implements ValidationRule
{
    public function __construct(private readonly DestinationGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The URL must be a string.');

            return;
        }

        try {
            $this->guard->inspect($value);
        } catch (DestinationRejected $exception) {
            $fail($exception->getMessage());
        }
    }
}
