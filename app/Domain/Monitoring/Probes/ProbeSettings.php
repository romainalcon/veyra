<?php

namespace App\Domain\Monitoring\Probes;

/**
 * Instance-level probe settings. These are operator decisions (environment
 * variables), never organization settings.
 */
final readonly class ProbeSettings
{
    /**
     * @param  list<int>  $allowedPorts  HTTP(S) ports
     * @param  list<int>  $tcpAllowedPorts  TCP monitor ports
     */
    public function __construct(
        public bool $allowPrivateNetworks,
        public array $allowedPorts,
        public int $maxRedirects,
        public string $userAgent,
        public array $tcpAllowedPorts = [],
    ) {}

    public static function fromConfig(): self
    {
        $isCloud = config('veyra.edition') === 'cloud';

        /** @var array<int, int|string> $ports */
        $ports = (array) config('veyra.probes.allowed_ports', [80, 443]);
        /** @var array<int, int|string> $tcpPorts */
        $tcpPorts = (array) config('veyra.probes.tcp_allowed_ports', []);

        return new self(
            allowPrivateNetworks: ! $isCloud && (bool) config('veyra.probes.allow_private_networks', false),
            allowedPorts: array_values(array_unique(array_map('intval', $ports))),
            maxRedirects: max(0, (int) config('veyra.probes.max_redirects', 3)),
            userAgent: (string) config('veyra.probes.user_agent', 'Veyra-Uptime-Monitor/1.0'),
            tcpAllowedPorts: array_values(array_unique(array_filter(
                array_map('intval', $tcpPorts),
                fn (int $port): bool => $port >= 1 && $port <= 65535,
            ))),
        );
    }
}
