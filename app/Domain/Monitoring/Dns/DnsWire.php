<?php

namespace App\Domain\Monitoring\Dns;

use App\Domain\Monitoring\Enums\DnsRecordType;

/**
 * Minimal DNS wire format (RFC 1035, EDNS0 from RFC 6891): one question,
 * recursion desired, and a bounded decoder for the record types monitors
 * can query. Decoding never trusts the response: every offset is checked,
 * compression pointers may only point backwards and are limited, and the
 * question must echo the query (an unrelated or spoofed answer is rejected).
 *
 * Normalized answers: A/AAAA in canonical text form, CNAME/NS lower-case
 * without trailing dot, MX as "preference host", TXT as its character
 * strings concatenated (RFC 7208 §3.3), valid UTF-8, at most 1024 bytes.
 */
final class DnsWire
{
    public const MAX_ANSWERS = 50;

    public const MAX_TXT_BYTES = 1024;

    /**
     * EDNS0 UDP payload size advertised (DNS flag day 2020 recommendation).
     */
    public const UDP_PAYLOAD_SIZE = 1232;

    private const MAX_POINTER_JUMPS = 32;

    /**
     * @param  string  $name  ASCII host name, without trailing dot
     */
    public static function query(int $id, string $name, DnsRecordType $type): string
    {
        $header = pack('nnnnnn', $id & 0xFFFF, 0x0100, 1, 0, 0, 1);
        $question = self::encodeName($name).pack('nn', $type->code(), 1);
        // OPT pseudo-record: root name, type 41, class = UDP payload size, no extended flags.
        $opt = "\x00".pack('nnNn', 41, self::UDP_PAYLOAD_SIZE, 0, 0);

        return $header.$question.$opt;
    }

    public static function encodeName(string $name): string
    {
        $encoded = '';

        foreach (explode('.', rtrim($name, '.')) as $label) {
            if ($label === '' || strlen($label) > 63) {
                throw new DnsQueryFailed('Invalid name.');
            }

            $encoded .= chr(strlen($label)).$label;
        }

        if (strlen($encoded) > 254) {
            throw new DnsQueryFailed('Invalid name.');
        }

        return $encoded."\x00";
    }

    /**
     * @throws DnsQueryFailed
     */
    public static function parse(string $message, int $id, string $name, DnsRecordType $type): DnsResponse
    {
        $length = strlen($message);

        if ($length < 12) {
            throw new DnsQueryFailed('Malformed DNS response.');
        }

        /** @var array{id: int, flags: int, qd: int, an: int} $header */
        $header = unpack('nid/nflags/nqd/nan', $message);

        if ($header['id'] !== ($id & 0xFFFF) || ($header['flags'] & 0x8000) === 0) {
            throw new UnrelatedDnsResponse;
        }

        if ($header['qd'] !== 1) {
            throw new DnsQueryFailed('Malformed DNS response.');
        }

        $offset = 12;
        $questionName = self::readName($message, $offset);
        $question = self::read($message, $offset, 4);
        /** @var array{type: int, class: int} $fields */
        $fields = unpack('ntype/nclass', $question);

        if (strcasecmp($questionName, rtrim($name, '.')) !== 0 || $fields['type'] !== $type->code() || $fields['class'] !== 1) {
            throw new UnrelatedDnsResponse;
        }

        $answers = [];

        for ($index = 0; $index < $header['an']; $index++) {
            self::readName($message, $offset);
            /** @var array{type: int, class: int, ttl: int, length: int} $record */
            $record = unpack('ntype/nclass/Nttl/nlength', self::read($message, $offset, 10));
            $rdataOffset = $offset;
            self::read($message, $offset, $record['length']);

            if ($record['type'] !== $type->code() || $record['class'] !== 1 || count($answers) >= self::MAX_ANSWERS) {
                continue;
            }

            $answers[] = self::decodeRdata($message, $rdataOffset, $record['length'], $type);
        }

        return new DnsResponse(
            rcode: $header['flags'] & 0x000F,
            answers: array_values(array_unique($answers)),
            truncated: ($header['flags'] & 0x0200) !== 0,
        );
    }

    private static function decodeRdata(string $message, int $offset, int $length, DnsRecordType $type): string
    {
        $end = $offset + $length;

        $value = match ($type) {
            DnsRecordType::A, DnsRecordType::Aaaa => self::address(self::read($message, $offset, $length), $type),
            DnsRecordType::Cname, DnsRecordType::Ns => strtolower(self::readName($message, $offset)),
            DnsRecordType::Mx => self::mx($message, $offset),
            DnsRecordType::Txt => self::txt(self::read($message, $offset, $length)),
        };

        if ($offset > $end) {
            throw new DnsQueryFailed('Malformed DNS response.');
        }

        return $value;
    }

    private static function address(string $bytes, DnsRecordType $type): string
    {
        if (strlen($bytes) !== ($type === DnsRecordType::A ? 4 : 16)) {
            throw new DnsQueryFailed('Malformed DNS response.');
        }

        return (string) inet_ntop($bytes);
    }

    private static function mx(string $message, int &$offset): string
    {
        /** @var array{preference: int} $preference */
        $preference = unpack('npreference', self::read($message, $offset, 2));

        return $preference['preference'].' '.strtolower(self::readName($message, $offset));
    }

    private static function txt(string $rdata): string
    {
        $text = '';
        $position = 0;

        while ($position < strlen($rdata)) {
            $size = ord($rdata[$position]);
            $text .= substr($rdata, $position + 1, $size);
            $position += $size + 1;
        }

        return mb_scrub(substr($text, 0, self::MAX_TXT_BYTES), 'UTF-8');
    }

    /**
     * Reads a possibly compressed name and advances `$offset` past it.
     */
    private static function readName(string $message, int &$offset): string
    {
        $labels = [];
        $position = $offset;
        $jumps = 0;
        $jumped = false;
        $total = 0;

        while (true) {
            $size = ord(self::read($message, $position, 1));

            if ($size === 0) {
                break;
            }

            if (($size & 0xC0) === 0xC0) {
                $target = (($size & 0x3F) << 8) | ord(self::read($message, $position, 1));

                // Pointers must go backwards, which also rules out loops.
                if ($target >= $position - 2 || ++$jumps > self::MAX_POINTER_JUMPS) {
                    throw new DnsQueryFailed('Malformed DNS response.');
                }

                if (! $jumped) {
                    $offset = $position;
                    $jumped = true;
                }

                $position = $target;

                continue;
            }

            if ($size > 63) {
                throw new DnsQueryFailed('Malformed DNS response.');
            }

            $total += $size + 1;

            if ($total > 255) {
                throw new DnsQueryFailed('Malformed DNS response.');
            }

            $labels[] = self::read($message, $position, $size);
        }

        if (! $jumped) {
            $offset = $position;
        }

        return implode('.', $labels);
    }

    private static function read(string $message, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($message)) {
            throw new DnsQueryFailed('Malformed DNS response.');
        }

        $bytes = substr($message, $offset, $length);
        $offset += $length;

        return $bytes;
    }
}
