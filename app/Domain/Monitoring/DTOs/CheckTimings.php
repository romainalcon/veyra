<?php

namespace App\Domain\Monitoring\DTOs;

/**
 * Phase breakdown of one check, in milliseconds (ADR-050). Every phase is
 * nullable: older checks, heartbeats and phases a probe never reached have
 * none. `redirectMs` is the time spent on the hops before the final one.
 */
final readonly class CheckTimings
{
    public const PHASES = ['dns', 'connect', 'tls', 'ttfb', 'transfer', 'redirect'];

    public function __construct(
        public ?int $dnsMs = null,
        public ?int $connectMs = null,
        public ?int $tlsMs = null,
        public ?int $ttfbMs = null,
        public ?int $transferMs = null,
        public ?int $redirectMs = null,
    ) {}

    /**
     * Phases of the final hop from the cURL transfer info of the pinned
     * connection. Name resolution is not taken from cURL: the host is pinned
     * (CURLOPT_RESOLVE), so the probe measures its own validated resolution.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function fromHandlerStats(array $stats, ?int $dnsMs, ?int $redirectMs): self
    {
        $lookup = self::microseconds($stats, 'namelookup_time');
        $connect = self::microseconds($stats, 'connect_time');
        $appConnect = self::microseconds($stats, 'appconnect_time');
        $firstByte = self::microseconds($stats, 'starttransfer_time');
        $total = self::microseconds($stats, 'total_time');

        // appconnect is 0 for plain HTTP (and while the handshake has not completed).
        $tlsEnd = $appConnect !== null && $connect !== null && $appConnect >= $connect && $appConnect > 0 ? $appConnect : null;
        $requestStart = $tlsEnd ?? $connect;

        return new self(
            dnsMs: $dnsMs,
            connectMs: self::span($connect !== null && $connect > 0 ? ($lookup ?? 0) : null, $connect),
            tlsMs: self::span($connect, $tlsEnd),
            ttfbMs: self::span($requestStart, $firstByte !== null && $firstByte > 0 ? $firstByte : null),
            transferMs: self::span($firstByte !== null && $firstByte > 0 ? $firstByte : null, $total),
            redirectMs: $redirectMs,
        );
    }

    /**
     * @param  array<string, mixed>|null  $row  a monitor_checks row or model attributes
     */
    public static function fromRow(?array $row): self
    {
        $value = fn (string $key): ?int => isset($row[$key]) ? (int) $row[$key] : null;

        return new self($value('dns_ms'), $value('connect_ms'), $value('tls_ms'), $value('ttfb_ms'), $value('transfer_ms'), $value('redirect_ms'));
    }

    public function isEmpty(): bool
    {
        return array_filter($this->toColumns(), fn (?int $value): bool => $value !== null) === [];
    }

    /**
     * @return array{dns_ms: int|null, connect_ms: int|null, tls_ms: int|null, ttfb_ms: int|null, transfer_ms: int|null, redirect_ms: int|null}
     */
    public function toColumns(): array
    {
        return [
            'dns_ms' => $this->dnsMs,
            'connect_ms' => $this->connectMs,
            'tls_ms' => $this->tlsMs,
            'ttfb_ms' => $this->ttfbMs,
            'transfer_ms' => $this->transferMs,
            'redirect_ms' => $this->redirectMs,
        ];
    }

    /**
     * @return array{dns: int|null, connect: int|null, tls: int|null, ttfb: int|null, transfer: int|null, redirect: int|null}
     */
    public function toArray(): array
    {
        return [
            'dns' => $this->dnsMs,
            'connect' => $this->connectMs,
            'tls' => $this->tlsMs,
            'ttfb' => $this->ttfbMs,
            'transfer' => $this->transferMs,
            'redirect' => $this->redirectMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private static function microseconds(array $stats, string $name): ?int
    {
        $micro = $stats["{$name}_us"] ?? null;

        if (is_numeric($micro)) {
            return (int) $micro;
        }

        $seconds = $stats[$name] ?? null;

        return is_numeric($seconds) ? (int) round((float) $seconds * 1_000_000) : null;
    }

    private static function span(?int $from, ?int $to): ?int
    {
        if ($from === null || $to === null || $to < $from) {
            return null;
        }

        return (int) round(($to - $from) / 1000);
    }

    public static function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
