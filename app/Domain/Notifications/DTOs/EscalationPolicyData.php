<?php

namespace App\Domain\Notifications\DTOs;

/**
 * A validated escalation policy: steps are ordered, with strictly
 * increasing delays.
 */
final readonly class EscalationPolicyData
{
    /**
     * @param  list<array{delay_minutes: int, channel_ids: list<string>}>  $steps
     */
    public function __construct(
        public string $name,
        public bool $isDefault,
        public array $steps,
    ) {}
}
