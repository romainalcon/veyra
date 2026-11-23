<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Dns\DnsAnswerMatcher;
use App\Domain\Monitoring\Dns\DnsNameservers;
use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Dns\DnsRecordName;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\DTOs\CheckTimings;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\DnsQueryClient;
use App\Domain\Monitoring\Probes\Contracts\MonitorProbe;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Queries one record of a DNS monitor (ADR-042). The name servers are the
 * system ones or a fixed public resolver, never an address chosen by the
 * organization. Servers are tried in order within the monitor timeout; a
 * SERVFAIL or REFUSED answer moves on to the next one.
 *
 * Outcomes are distinct check results: `dns_nxdomain` (the name does not
 * exist), `dns_no_answer` (no record of this type), `dns_mismatch` (answers
 * differ from the expected values), `dns_failure` (no usable answer) and
 * `timeout`.
 */
final class DnsProbe implements MonitorProbe
{
    /**
     * Shown instead of addresses outside the probe policy when the system
     * resolver answered (split-horizon zones must not leak, ADR-042).
     */
    public const REDACTED_ADDRESS = 'non-public address';

    public function __construct(
        private readonly DnsQueryClient $client,
        private readonly DnsNameservers $nameservers,
        private readonly ProbeSettings $settings,
    ) {}

    public function canProbe(Monitor $monitor): bool
    {
        return $monitor->dnsConfig !== null;
    }

    /**
     * Null (dispatch skipped, UNKNOWN) when the operator disabled the
     * monitor's resolver after it was saved: that says nothing about the domain.
     */
    public function probe(Monitor $monitor, CarbonImmutable $scheduledFor): ?ProbeResult
    {
        $config = $monitor->dnsConfig ?? throw new LogicException('DNS monitor without configuration.');

        if (! in_array($config->resolver, DnsResolverChoice::allowed(), true)) {
            return null;
        }

        return $this->run(
            $config->record_name,
            $config->record_type,
            $config->resolver,
            $config->expected_values ?? [],
            $config->match_mode,
            $monitor->timeout_seconds,
        );
    }

    /**
     * @param  list<string>  $expected  normalized expected values
     */
    public function run(string $name, DnsRecordType $type, DnsResolverChoice $resolver, array $expected, DnsMatchMode $mode, int $timeoutSeconds): ProbeResult
    {
        $startedAt = hrtime(true);
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            $name = DnsRecordName::fromSettings($this->settings)->normalize($name);
        } catch (DestinationRejected $exception) {
            return ProbeResult::failure($exception->errorCode, $this->elapsed($startedAt));
        }

        $response = null;
        $timedOut = false;

        foreach ($this->nameservers->for($resolver) as $nameserver) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timedOut = true;

                break;
            }

            try {
                $response = $this->client->query($nameserver, $name, $type, $remaining);
            } catch (DnsQueryFailed $exception) {
                $timedOut = $exception->timedOut;

                continue;
            }

            if (! in_array($response->rcode, [DnsResponse::NOERROR, DnsResponse::NXDOMAIN], true)) {
                continue;
            }

            break;
        }

        $duration = $this->elapsed($startedAt);
        $metadata = ['resolver' => $resolver->value];

        if ($response === null) {
            return ProbeResult::failure($timedOut ? ProbeErrorCode::Timeout : ProbeErrorCode::DnsFailure, $duration, null, $metadata);
        }

        $metadata += ['rcode' => $response->rcodeName(), 'answers' => count($response->answers)];
        $answers = $this->displayable($response->answers, $type, $resolver);

        $errorCode = match (true) {
            $response->rcode === DnsResponse::NXDOMAIN => ProbeErrorCode::DnsNxDomain,
            $response->rcode !== DnsResponse::NOERROR => ProbeErrorCode::DnsFailure,
            $response->answers === [] => ProbeErrorCode::DnsNoAnswer,
            ! DnsAnswerMatcher::matches($type, $mode, $response->answers, $expected) => ProbeErrorCode::DnsMismatch,
            default => null,
        };

        // A failing server's answer (SERVFAIL, REFUSED) says nothing about the records.
        $recorded = in_array($response->rcode, [DnsResponse::NOERROR, DnsResponse::NXDOMAIN], true) ? $answers : null;

        return new ProbeResult($errorCode === null, $duration, null, $errorCode, $metadata, dnsAnswers: $recorded, timings: new CheckTimings(dnsMs: $duration));
    }

    /**
     * @param  list<string>  $answers
     * @return list<string>
     */
    private function displayable(array $answers, DnsRecordType $type, DnsResolverChoice $resolver): array
    {
        if ($resolver !== DnsResolverChoice::System || ! in_array($type, [DnsRecordType::A, DnsRecordType::Aaaa], true)) {
            return $answers;
        }

        $policy = AddressPolicy::fromSettings($this->settings);

        return array_values(array_unique(array_map(fn (string $ip): string => $policy->allows($ip) ? $ip : self::REDACTED_ADDRESS, $answers)));
    }

    private function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
