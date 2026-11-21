<?php

namespace App\Domain\Monitoring\Probes;

use RuntimeException;

/**
 * Thrown from transfer callbacks to abort responses above the size cap.
 */
class ResponseTooLarge extends RuntimeException {}
