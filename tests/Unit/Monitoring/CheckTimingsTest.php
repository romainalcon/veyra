<?php

use App\Domain\Monitoring\DTOs\CheckTimings;

test('phases of an HTTPS hop are derived from the cURL transfer info', function () {
    $timings = CheckTimings::fromHandlerStats([
        'namelookup_time_us' => 1_000,
        'connect_time_us' => 21_000,
        'appconnect_time_us' => 61_000,
        'starttransfer_time_us' => 171_000,
        'total_time_us' => 180_000,
    ], dnsMs: 12, redirectMs: 0);

    expect($timings->toArray())->toBe([
        'dns' => 12, 'connect' => 20, 'tls' => 40, 'ttfb' => 110, 'transfer' => 9, 'redirect' => 0,
    ]);
});

test('plain HTTP has no TLS phase and seconds are accepted when microseconds are missing', function () {
    $timings = CheckTimings::fromHandlerStats([
        'namelookup_time' => 0.0,
        'connect_time' => 0.015,
        'appconnect_time' => 0.0,
        'starttransfer_time' => 0.065,
        'total_time' => 0.07,
    ], dnsMs: null, redirectMs: null);

    expect($timings->tlsMs)->toBeNull()
        ->and($timings->connectMs)->toBe(15)
        ->and($timings->ttfbMs)->toBe(50)
        ->and($timings->transferMs)->toBe(5);
});

test('phases that were never reached or are inconsistent are null', function () {
    $timings = CheckTimings::fromHandlerStats([
        'namelookup_time_us' => 0,
        'connect_time_us' => 0,
        'appconnect_time_us' => 0,
        'starttransfer_time_us' => 0,
        'total_time_us' => 10_000_000,
    ], dnsMs: 5, redirectMs: 0);

    expect($timings->connectMs)->toBeNull()
        ->and($timings->tlsMs)->toBeNull()
        ->and($timings->ttfbMs)->toBeNull()
        ->and($timings->transferMs)->toBeNull()
        ->and($timings->dnsMs)->toBe(5);

    expect(CheckTimings::fromHandlerStats([], null, null)->isEmpty())->toBeTrue()
        ->and(CheckTimings::fromRow(null)->isEmpty())->toBeTrue()
        ->and(CheckTimings::fromRow(['dns_ms' => '7'])->dnsMs)->toBe(7);
});
