<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Probes\Contracts\DnsResolver;

class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            $ips = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($ips));
    }
}
