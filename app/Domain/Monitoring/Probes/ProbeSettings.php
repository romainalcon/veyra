<?php

namespace App\Domain\Monitoring\Probes;

/**
 * Instance-level probe settings. These are operator decisions (environment
 * variables), never organization settings.
 */
final readonly class ProbeSettings
{
    /**
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        public bool $allowPrivateNetworks,
        public array $allowedPorts,
        public int $maxRedirects,
        public string $userAgent,
    ) {}

    public static function fromConfig(): self
    {
        $isCloud = config('veyra.edition') === 'cloud';

        /** @var array<int, int|string> $ports */
        $ports = (array) config('veyra.probes.allowed_ports', [80, 443]);

        return new self(
            allowPrivateNetworks: ! $isCloud && (bool) config('veyra.probes.allow_private_networks', false),
            allowedPorts: array_values(array_unique(array_map('intval', $ports))),
            maxRedirects: max(0, (int) config('veyra.probes.max_redirects', 3)),
            userAgent: (string) config('veyra.probes.user_agent', 'Veyra-Uptime-Monitor/1.0'),
        );
    }
}
