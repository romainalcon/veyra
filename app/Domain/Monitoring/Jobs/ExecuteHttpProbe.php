<?php

namespace App\Domain\Monitoring\Jobs;

/**
 * Kept so that jobs queued by Veyra 1.1 (serialized with this class name)
 * still run after an upgrade to 1.2. New dispatches use ExecuteProbe.
 *
 * @deprecated since 1.2, to be removed in 1.3.
 */
class ExecuteHttpProbe extends ExecuteProbe {}
