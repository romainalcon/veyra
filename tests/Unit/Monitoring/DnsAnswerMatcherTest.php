<?php

use App\Domain\Monitoring\Dns\DnsAnswerMatcher;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;

test('expected values are normalized per record type', function (DnsRecordType $type, string $value, ?string $expected) {
    expect(DnsAnswerMatcher::normalize($type, $value))->toBe($expected);
})->with([
    'IPv4' => [DnsRecordType::A, ' 93.184.215.14 ', '93.184.215.14'],
    'IPv6 in A' => [DnsRecordType::A, '2001:db8::1', null],
    'IPv6 canonical' => [DnsRecordType::Aaaa, '2606:2800:021F:cb07:0:0:0:1', '2606:2800:21f:cb07::1'],
    'name lower-cased' => [DnsRecordType::Cname, 'CDN.Example.NET.', 'cdn.example.net'],
    'invalid name' => [DnsRecordType::Ns, 'ns1 example.net', null],
    'MX with preference' => [DnsRecordType::Mx, '010  Mail.example.com', '10 mail.example.com'],
    'MX host only' => [DnsRecordType::Mx, 'mail.example.com', 'mail.example.com'],
    'MX preference too large' => [DnsRecordType::Mx, '70000 mail.example.com', null],
    'TXT kept as is' => [DnsRecordType::Txt, 'v=spf1 -all', 'v=spf1 -all'],
    'empty' => [DnsRecordType::Txt, '   ', null],
]);

test('answers are compared with the expected values', function (DnsRecordType $type, DnsMatchMode $mode, array $answers, array $expected, bool $matches) {
    expect(DnsAnswerMatcher::matches($type, $mode, $answers, $expected))->toBe($matches);
})->with([
    'any answer' => [DnsRecordType::A, DnsMatchMode::Contains, ['93.184.215.14'], [], true],
    'no answer never matches' => [DnsRecordType::A, DnsMatchMode::Contains, [], [], false],
    'contains, extra answers allowed' => [DnsRecordType::A, DnsMatchMode::Contains, ['93.184.215.14', '93.184.215.15'], ['93.184.215.15'], true],
    'contains, one missing' => [DnsRecordType::A, DnsMatchMode::Contains, ['93.184.215.14'], ['93.184.215.14', '93.184.215.15'], false],
    'exact, same set in another order' => [DnsRecordType::A, DnsMatchMode::Exact, ['93.184.215.15', '93.184.215.14'], ['93.184.215.14', '93.184.215.15'], true],
    'exact, extra answer' => [DnsRecordType::A, DnsMatchMode::Exact, ['93.184.215.14', '93.184.215.99'], ['93.184.215.14'], false],
    'MX host ignores preference' => [DnsRecordType::Mx, DnsMatchMode::Exact, ['10 mail.example.com'], ['mail.example.com'], true],
    'MX preference must match when given' => [DnsRecordType::Mx, DnsMatchMode::Contains, ['10 mail.example.com'], ['20 mail.example.com'], false],
    'TXT contains a fragment' => [DnsRecordType::Txt, DnsMatchMode::Contains, ['v=spf1 include:_spf.example.com -all'], ['include:_spf.example.com'], true],
    'TXT exact needs the whole string' => [DnsRecordType::Txt, DnsMatchMode::Exact, ['v=spf1 include:_spf.example.com -all'], ['include:_spf.example.com'], false],
    'TXT is case-sensitive' => [DnsRecordType::Txt, DnsMatchMode::Contains, ['google-site-verification=AbC'], ['google-site-verification=abc'], false],
]);
