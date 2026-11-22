<?php

namespace App\Domain\Notifications\Rules;

use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Notifications\Channels\WebhookDestination;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Early feedback when saving a webhook channel. The sender re-validates the
 * destination before each delivery, since DNS answers can change.
 */
class WebhookUrl implements ValidationRule
{
    public function __construct(private readonly WebhookDestination $destinations) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The URL must be a string.'));

            return;
        }

        try {
            $this->destinations->inspect($value);
        } catch (DestinationRejected $exception) {
            $fail($exception->translatedMessage());
        }
    }
}
