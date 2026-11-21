<?php

namespace App\Domain\Monitoring\DTOs;

use App\Domain\Localization\TranslatableMessage;
use InvalidArgumentException;

/**
 * An expected status expression that cannot be parsed.
 */
final class InvalidExpectedStatuses extends InvalidArgumentException
{
    use TranslatableMessage;

    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(string $message, array $replace = [])
    {
        $this->rememberTemplate($message, $replace);

        parent::__construct(self::fillTemplate($message, $replace));
    }
}
