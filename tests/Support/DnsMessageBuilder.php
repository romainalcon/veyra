<?php

namespace Tests\Support;

use App\Domain\Monitoring\Dns\DnsWire;
use App\Domain\Monitoring\Enums\DnsRecordType;

/**
 * Builds raw DNS responses for decoder tests. Record owners default to a
 * compression pointer to the question name (offset 12).
 */
final class DnsMessageBuilder
{
    /**
     * @var list<string>
     */
    private array $records = [];

    private int $flags = 0x8180;

    public function __construct(
        private int $id,
        private string $name,
        private DnsRecordType $type,
    ) {}

    public function rcode(int $rcode): self
    {
        $this->flags = ($this->flags & ~0x000F) | $rcode;

        return $this;
    }

    public function truncated(): self
    {
        $this->flags |= 0x0200;

        return $this;
    }

    public function record(int $type, string $rdata, string $owner = "\xC0\x0C"): self
    {
        $this->records[] = $owner.pack('nnNn', $type, 1, 300, strlen($rdata)).$rdata;

        return $this;
    }

    public function a(string $ip): self
    {
        return $this->record(str_contains($ip, ':') ? 28 : 1, (string) inet_pton($ip));
    }

    public function build(?string $questionName = null, ?int $questionType = null): string
    {
        return pack('nnnnnn', $this->id, $this->flags, 1, count($this->records), 0, 0)
            .DnsWire::encodeName($questionName ?? $this->name)
            .pack('nn', $questionType ?? $this->type->code(), 1)
            .implode('', $this->records);
    }

    public static function name(string $name): string
    {
        return DnsWire::encodeName($name);
    }

    public static function txt(string ...$strings): string
    {
        return implode('', array_map(fn (string $string): string => chr(strlen($string)).$string, $strings));
    }
}
