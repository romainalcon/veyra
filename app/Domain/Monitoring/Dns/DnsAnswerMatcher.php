<?php

namespace App\Domain\Monitoring\Dns;

use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;

/**
 * Normalizes expected values and compares them with answers (ADR-042).
 *
 * - A/AAAA: canonical address; CNAME/NS: lower-case, no trailing dot;
 *   MX: "preference host", or "host" alone to ignore the preference;
 *   TXT: compared as is; in `contains` mode a TXT value only has to be part
 *   of an answer (e.g. "include:_spf.example.com").
 * - `contains`: every expected value matches an answer.
 * - `exact`: additionally, every answer matches an expected value.
 */
final class DnsAnswerMatcher
{
    /**
     * @return string|null null when the value is not valid for the type
     */
    public static function normalize(DnsRecordType $type, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($type) {
            DnsRecordType::A => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $value,
            DnsRecordType::Aaaa => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : (string) inet_ntop((string) inet_pton($value)),
            DnsRecordType::Cname, DnsRecordType::Ns => self::hostName($value),
            DnsRecordType::Mx => self::mx($value),
            DnsRecordType::Txt => mb_strlen($value) <= DnsWire::MAX_TXT_BYTES ? $value : null,
        };
    }

    /**
     * @param  list<string>  $answers  normalized answers
     * @param  list<string>  $expected  normalized expected values (empty: any answer)
     */
    public static function matches(DnsRecordType $type, DnsMatchMode $mode, array $answers, array $expected): bool
    {
        if ($answers === []) {
            return false;
        }

        foreach ($expected as $value) {
            if (! self::anyMatches($type, $mode, $answers, $value)) {
                return false;
            }
        }

        if ($mode === DnsMatchMode::Exact && $expected !== []) {
            foreach ($answers as $answer) {
                if (! self::anyMatches($type, $mode, $expected, $answer, answerFirst: false)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function anyMatches(DnsRecordType $type, DnsMatchMode $mode, array $candidates, string $value, bool $answerFirst = true): bool
    {
        foreach ($candidates as $candidate) {
            [$answer, $expected] = $answerFirst ? [$candidate, $value] : [$value, $candidate];

            if (self::valueMatches($type, $mode, $answer, $expected)) {
                return true;
            }
        }

        return false;
    }

    private static function valueMatches(DnsRecordType $type, DnsMatchMode $mode, string $answer, string $expected): bool
    {
        if ($answer === $expected) {
            return true;
        }

        return match ($type) {
            DnsRecordType::Mx => ! str_contains($expected, ' ') && str_ends_with($answer, ' '.$expected),
            DnsRecordType::Txt => $mode === DnsMatchMode::Contains && str_contains($answer, $expected),
            default => false,
        };
    }

    private static function hostName(string $value): ?string
    {
        $host = rtrim(strtolower($value), '.');
        $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $host;

        if ($ascii === false || preg_match('/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9_](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii) !== 1) {
            return null;
        }

        return $ascii;
    }

    private static function mx(string $value): ?string
    {
        if (preg_match('/^(?:(\d{1,5})\s+)?(\S+)$/', $value, $matches) !== 1) {
            return null;
        }

        $host = self::hostName($matches[2]);

        if ($host === null || ($matches[1] !== '' && (int) $matches[1] > 65535)) {
            return null;
        }

        return $matches[1] === '' ? $host : ((int) $matches[1]).' '.$host;
    }
}
