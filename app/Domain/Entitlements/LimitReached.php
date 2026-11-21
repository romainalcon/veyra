<?php

namespace App\Domain\Entitlements;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Localization\TranslatableMessage;
use RuntimeException;

/**
 * Raised when adding a resource would exceed an organization limit. Rendered
 * as a validation error (`limit`) with a toast; getMessage() is English,
 * translatedMessage() follows the request language.
 */
class LimitReached extends RuntimeException
{
    use TranslatableMessage;

    public function __construct(public readonly Feature $feature, public readonly int $limit)
    {
        $this->rememberTemplate($feature->limitMessage(), ['limit' => $limit]);

        parent::__construct(self::fillTemplate($feature->limitMessage(), ['limit' => $limit]));
    }
}
