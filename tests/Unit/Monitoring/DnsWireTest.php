<?php

use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\Dns\DnsWire;
use App\Domain\Monitoring\Dns\UnrelatedDnsResponse;
use App\Domain\Monitoring\Enums\DnsRecordType;
use Tests\Support\DnsMessageBuilder;

test('a query carries one question, recursion desired and an EDNS0 record', function () {
    $query = DnsWire::query(0xBEEF, 'Example.com', DnsRecordType::Mx);

    expect(unpack('nid/nflags/nqd/nan/nns/nar', $query))->toBe(['id' => 0xBEEF, 'flags' => 0x0100, 'qd' => 1, 'an' => 0, 'ns' => 0, 'ar' => 1])
        ->and(substr($query, 12, 13))->toBe("\x07Example\x03com\x00")
        ->and(unpack('ntype/nclass', substr($query, 25, 4)))->toBe(['type' => 15, 'class' => 1])
        ->and(unpack('ntype/nsize', substr($query, 30, 4)))->toBe(['type' => 41, 'size' => DnsWire::UDP_PAYLOAD_SIZE]);
});

test('names that cannot be encoded are refused', function (string $name) {
    DnsWire::encodeName($name);
})->with([
    'empty label' => ['example..com'],
    'label too long' => [str_repeat('a', 64).'.com'],
    'name too long' => [implode('.', array_fill(0, 5, str_repeat('a', 63)))],
])->throws(DnsQueryFailed::class);

test('addresses are decoded in canonical form and other record types ignored', function () {
    $message = (new DnsMessageBuilder(7, 'example.com', DnsRecordType::Aaaa))
        ->record(5, DnsMessageBuilder::name('cdn.example.net'))
        ->a('2606:2800:021f:cb07:6820:80da:af6b:8b2c')
        ->a('2606:2800:021f:cb07:6820:80da:af6b:8b2c')
        ->build();

    $response = DnsWire::parse($message, 7, 'example.com', DnsRecordType::Aaaa);

    expect($response->rcode)->toBe(DnsResponse::NOERROR)
        ->and($response->answers)->toBe(['2606:2800:21f:cb07:6820:80da:af6b:8b2c']);
});

test('compressed names, MX preferences and TXT strings are decoded', function () {
    $cname = (new DnsMessageBuilder(1, 'www.example.com', DnsRecordType::Cname))
        ->record(5, "\x03CDN\xC0\x10") // "cdn" + pointer to "example.com" in the question
        ->build();
    $mx = (new DnsMessageBuilder(2, 'example.com', DnsRecordType::Mx))
        ->record(15, pack('n', 10).DnsMessageBuilder::name('Mail.Example.com.'))
        ->record(15, pack('n', 20)."\x06backup\xC0\x0C")
        ->build();
    $txt = (new DnsMessageBuilder(3, 'example.com', DnsRecordType::Txt))
        ->record(16, DnsMessageBuilder::txt('v=spf1 include:_spf.', 'example.com -all'))
        ->build();

    expect(DnsWire::parse($cname, 1, 'www.example.com', DnsRecordType::Cname)->answers)->toBe(['cdn.example.com'])
        ->and(DnsWire::parse($mx, 2, 'example.com', DnsRecordType::Mx)->answers)->toBe(['10 mail.example.com', '20 backup.example.com'])
        ->and(DnsWire::parse($txt, 3, 'example.com', DnsRecordType::Txt)->answers)->toBe(['v=spf1 include:_spf.example.com -all']);
});

test('the response code and truncation flag are reported', function () {
    $message = (new DnsMessageBuilder(9, 'missing.example.com', DnsRecordType::A))->rcode(3)->truncated()->build();

    $response = DnsWire::parse($message, 9, 'missing.example.com', DnsRecordType::A);

    expect($response->rcode)->toBe(DnsResponse::NXDOMAIN)
        ->and($response->rcodeName())->toBe('NXDOMAIN')
        ->and($response->truncated)->toBeTrue()
        ->and($response->answers)->toBe([]);
});

test('responses to another query are rejected as unrelated', function (int $id, string $name, int $type, int $flags) {
    $message = (new DnsMessageBuilder(5, 'example.com', DnsRecordType::A))->a('93.184.215.14')->build($name, $type);
    $message = substr_replace($message, pack('nn', $id, $flags), 0, 4);

    DnsWire::parse($message, 5, 'example.com', DnsRecordType::A);
})->with([
    'other id' => [6, 'example.com', 1, 0x8180],
    'other name' => [5, 'example.org', 1, 0x8180],
    'other type' => [5, 'example.com', 28, 0x8180],
    'a query, not a response' => [5, 'example.com', 1, 0x0100],
])->throws(UnrelatedDnsResponse::class);

test('malformed responses are rejected without reading out of bounds', function (string $message) {
    DnsWire::parse($message, 5, 'example.com', DnsRecordType::A);
})->with([
    'too short' => ["\x00\x05\x81\x80"],
    'truncated record' => [substr((new DnsMessageBuilder(5, 'example.com', DnsRecordType::A))->a('93.184.215.14')->build(), 0, -2)],
    'address of the wrong size' => [(new DnsMessageBuilder(5, 'example.com', DnsRecordType::A))->record(1, "\x01\x02\x03")->build()],
    'forward pointer' => [(new DnsMessageBuilder(5, 'example.com', DnsRecordType::A))->record(1, (string) inet_pton('93.184.215.14'), "\xC0\xFF")->build()],
    'self pointer' => [(new DnsMessageBuilder(5, 'example.com', DnsRecordType::A))->record(1, (string) inet_pton('93.184.215.14'), "\xC0\x1D")->build()],
])->throws(DnsQueryFailed::class);

test('answers are bounded in number and size', function () {
    $builder = new DnsMessageBuilder(5, 'example.com', DnsRecordType::Txt);

    for ($index = 0; $index < DnsWire::MAX_ANSWERS + 10; $index++) {
        $builder->record(16, DnsMessageBuilder::txt("token-{$index}"));
    }

    $builder->record(16, DnsMessageBuilder::txt(...array_fill(0, 6, str_repeat('x', 255))));
    $answers = DnsWire::parse($builder->build(), 5, 'example.com', DnsRecordType::Txt)->answers;

    expect($answers)->toHaveCount(DnsWire::MAX_ANSWERS);

    $long = (new DnsMessageBuilder(5, 'example.com', DnsRecordType::Txt))
        ->record(16, DnsMessageBuilder::txt(...array_fill(0, 6, str_repeat('x', 255))))
        ->build();

    expect(strlen(DnsWire::parse($long, 5, 'example.com', DnsRecordType::Txt)->answers[0]))->toBe(DnsWire::MAX_TXT_BYTES);
});
