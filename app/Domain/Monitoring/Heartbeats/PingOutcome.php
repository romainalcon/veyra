<?php

namespace App\Domain\Monitoring\Heartbeats;

enum PingOutcome
{
    /** A check was recorded (first ping of the period, recovery or failure signal). */
    case Recorded;

    /** The ping was accepted; the period already has a successful check. */
    case Accepted;

    /** The monitor is paused: the ping is acknowledged and ignored. */
    case Ignored;

    /** Unknown token or deleted monitor. */
    case NotFound;
}
