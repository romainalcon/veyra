<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Localization\TranslatableMessage;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use RuntimeException;

/**
 * Raised when a URL or one of its resolved addresses may not be probed.
 * Messages are safe to show to the monitor owner (no IPs of other hosts);
 * getMessage() is English, translatedMessage() follows the request language.
 */
class DestinationRejected extends RuntimeException
{
    use TranslatableMessage;

    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(public readonly ProbeErrorCode $errorCode, string $message, array $replace = [])
    {
        $this->rememberTemplate($message, $replace);

        parent::__construct(self::fillTemplate($message, $replace));
    }
}
