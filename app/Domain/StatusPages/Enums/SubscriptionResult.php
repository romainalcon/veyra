<?php

namespace App\Domain\StatusPages\Enums;

/**
 * Outcome of a subscription request. Accepted covers new, pending and
 * already confirmed addresses alike, so visitors cannot probe who subscribed.
 */
enum SubscriptionResult: string
{
    case Accepted = 'accepted';
    case Unavailable = 'unavailable';
}
