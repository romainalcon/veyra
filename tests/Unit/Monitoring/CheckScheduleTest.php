<?php

use App\Domain\Monitoring\Support\CheckSchedule;
use Carbon\CarbonImmutable;

test('the next slot follows the interval when on time', function () {
    $slot = CarbonImmutable::parse('2026-11-22 10:00:00');

    expect(CheckSchedule::next($slot, 60, $slot->addSeconds(5))->toDateTimeString())
        ->toBe('2026-11-22 10:01:00');
});

test('missed slots are skipped after an outage instead of being replayed', function () {
    $slot = CarbonImmutable::parse('2026-11-22 10:00:00');
    $now = CarbonImmutable::parse('2026-11-22 12:30:15');

    expect(CheckSchedule::next($slot, 60, $now)->toDateTimeString())
        ->toBe('2026-11-22 12:31:15');
});

test('dispatches become stale after the interval or five minutes', function () {
    expect(CheckSchedule::staleAfterSeconds(60))->toBe(300)
        ->and(CheckSchedule::staleAfterSeconds(3600))->toBe(3600);
});
