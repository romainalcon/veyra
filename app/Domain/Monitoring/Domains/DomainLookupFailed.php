<?php

namespace App\Domain\Monitoring\Domains;

use RuntimeException;

/**
 * The registration of a domain could not be read. The message is an
 * English source string, stored and translated when displayed; it never
 * contains response content.
 */
class DomainLookupFailed extends RuntimeException {}
