<?php

use App\Domain\Monitoring\Probes\AddressPolicy;

test('public addresses are allowed', function (string $ip) {
    expect((new AddressPolicy(false))->allows($ip))->toBeTrue();
})->with(['93.184.215.14', '1.1.1.1', '2606:4700:4700::1111']);

test('dangerous addresses are always blocked, even with private networks enabled', function (string $ip) {
    expect((new AddressPolicy(false))->allows($ip))->toBeFalse()
        ->and((new AddressPolicy(true))->allows($ip))->toBeFalse();
})->with([
    'loopback v4' => ['127.0.0.1'],
    'loopback range' => ['127.10.0.1'],
    'unspecified v4' => ['0.0.0.0'],
    'cloud metadata' => ['169.254.169.254'],
    'link-local v4' => ['169.254.10.10'],
    'alibaba metadata' => ['100.100.100.200'],
    'multicast v4' => ['224.0.0.1'],
    'broadcast' => ['255.255.255.255'],
    'documentation' => ['192.0.2.10'],
    'loopback v6' => ['::1'],
    'unspecified v6' => ['::'],
    'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
    'ipv4-mapped metadata' => ['::ffff:169.254.169.254'],
    'link-local v6' => ['fe80::1'],
    'multicast v6' => ['ff02::1'],
    'aws imds v6' => ['fd00:ec2::254'],
    'nat64' => ['64:ff9b::7f00:1'],
    '6to4' => ['2002:7f00:1::'],
    'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'not an ip' => ['localhost'],
]);

test('private networks are blocked unless the operator opts in', function (string $ip) {
    expect((new AddressPolicy(false))->allows($ip))->toBeFalse()
        ->and((new AddressPolicy(true))->allows($ip))->toBeTrue();
})->with([
    'rfc1918 10/8' => ['10.0.0.5'],
    'rfc1918 172.16/12' => ['172.20.1.1'],
    'rfc1918 192.168/16' => ['192.168.1.10'],
    'cgnat' => ['100.64.1.1'],
    'ula' => ['fd12:3456:789a::1'],
    'ipv4-mapped private' => ['::ffff:10.0.0.5'],
]);

test('ipv4-mapped ipv6 addresses are normalized', function () {
    expect(AddressPolicy::normalize('::ffff:192.168.1.1'))->toBe('192.168.1.1')
        ->and(AddressPolicy::normalize('2606:4700:4700:0:0:0:0:1111'))->toBe('2606:4700:4700::1111');
});
