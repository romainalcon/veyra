<?php

namespace App\Domain\Monitoring\Enums;

enum DispatchStatus: string
{
    /** Claimed by the scheduler, not yet pushed to Redis. */
    case Pending = 'pending';
    /** Pushed to the `probes` queue. */
    case Dispatched = 'dispatched';
    /** A check was recorded. */
    case Completed = 'completed';
    /** Intentionally not executed (paused/deleted monitor, too old). */
    case Skipped = 'skipped';
    /** Gave up after repeated delivery attempts; no check exists. */
    case Failed = 'failed';
}
