<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Destination;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\ProbeSettings;

/**
 * Applies the probe SSRF policy to webhook URLs (ADR-033): allowed ports,
 * host name rules and every DNS answer, through the same DestinationGuard.
 * HTTPS is required unless the instance allows private networks (never on
 * the cloud edition), so self-hosted users can reach an internal endpoint.
 * Used when saving a channel and again before each send.
 */
class WebhookDestination
{
    public function __construct(
        private readonly DestinationGuard $guard,
        private readonly ProbeSettings $settings,
    ) {}

    /**
     * @throws DestinationRejected
     */
    public function inspect(string $url): Destination
    {
        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));

        if ($scheme === 'http' && ! $this->settings->allowPrivateNetworks) {
            throw new DestinationRejected(ProbeErrorCode::InvalidUrl, 'Webhook URLs must use https on this instance.');
        }

        return $this->guard->inspect($url);
    }
}
