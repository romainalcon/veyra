<?php

use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Services\RecurrenceRule;
use Carbon\CarbonImmutable;

function rule(MaintenanceFrequency $frequency, string $startTime = '02:00', int $minutes = 60, array $weekdays = [], ?int $monthDay = null, string $startsOn = '2026-01-01', ?string $endsOn = null): RecurrenceRule
{
    return new RecurrenceRule($frequency, $weekdays, $monthDay, $startTime, $minutes, $startsOn, $endsOn);
}

/**
 * @return list<string> UTC starts as "Y-m-d H:i"
 */
function starts(RecurrenceRule $rule, string $from, string $until, string $timezone): array
{
    return array_map(
        fn (array $occurrence): string => $occurrence['starts_at']->format('Y-m-d H:i'),
        $rule->occurrencesBetween(CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($until, 'UTC'), $timezone),
    );
}

test('daily occurrences follow the local wall clock across the spring-forward change', function () {
    $occurrences = starts(rule(MaintenanceFrequency::Daily), '2026-03-27 00:00', '2026-03-30 23:00', 'Europe/Paris');

    // 02:00 does not exist on 29 March in Paris: it moves forward by the gap (03:00 CEST = 01:00 UTC).
    expect($occurrences)->toBe(['2026-03-27 01:00', '2026-03-28 01:00', '2026-03-29 01:00', '2026-03-30 00:00']);
});

test('an ambiguous start in the fall-back overlap takes the first instant', function () {
    $occurrences = starts(rule(MaintenanceFrequency::Daily, '02:30'), '2026-10-24 00:00', '2026-10-26 23:00', 'Europe/Paris');

    expect($occurrences)->toBe(['2026-10-24 00:30', '2026-10-25 00:30', '2026-10-26 01:30']);
});

test('the duration is real elapsed time, even across a change', function () {
    [$occurrence] = rule(MaintenanceFrequency::Daily, '01:00', 180)
        ->occurrencesBetween(CarbonImmutable::parse('2026-03-08 00:00'), CarbonImmutable::parse('2026-03-08 23:00'), 'America/New_York');

    expect($occurrence['starts_at']->toDateTimeString())->toBe('2026-03-08 06:00:00')
        ->and($occurrence['ends_at']->toDateTimeString())->toBe('2026-03-08 09:00:00');
});

test('weekly rules keep their local days and times in New York across the change', function () {
    $rule = rule(MaintenanceFrequency::Weekly, '23:30', weekdays: [1, 5]);

    expect(starts($rule, '2026-03-02 00:00', '2026-03-17 04:00', 'America/New_York'))->toBe([
        '2026-03-03 04:30', // Mon 2 Mar, EST
        '2026-03-07 04:30', // Fri 6 Mar, EST
        '2026-03-10 03:30', // Mon 9 Mar, EDT
        '2026-03-14 03:30', // Fri 13 Mar
        '2026-03-17 03:30', // Mon 16 Mar
    ]);
});

test('a monthly day beyond the month length means its last day, leap years included', function () {
    $rule = rule(MaintenanceFrequency::Monthly, '12:00', monthDay: 31, startsOn: '2027-12-01');

    expect(starts($rule, '2027-12-01 00:00', '2028-05-01 00:00', 'UTC'))->toBe([
        '2027-12-31 12:00',
        '2028-01-31 12:00',
        '2028-02-29 12:00',
        '2028-03-31 12:00',
        '2028-04-30 12:00',
    ]);

    $thirtieth = rule(MaintenanceFrequency::Monthly, '12:00', monthDay: 30, startsOn: '2026-01-01');

    expect(starts($thirtieth, '2026-02-01 00:00', '2026-03-31 00:00', 'UTC'))->toBe(['2026-02-28 12:00', '2026-03-30 12:00']);
});

test('the first and last local dates bound the series', function () {
    $rule = rule(MaintenanceFrequency::Daily, '00:30', startsOn: '2026-06-10', endsOn: '2026-06-12');

    // Tokyo is UTC+9: local 00:30 on 10 June is 15:30 UTC on 9 June.
    expect(starts($rule, '2026-06-01 00:00', '2026-06-30 00:00', 'Asia/Tokyo'))
        ->toBe(['2026-06-09 15:30', '2026-06-10 15:30', '2026-06-11 15:30']);
});

test('only starts within the range are returned', function () {
    $occurrences = starts(rule(MaintenanceFrequency::Daily, '10:00'), '2026-06-10 10:00', '2026-06-11 09:59', 'UTC');

    expect($occurrences)->toBe(['2026-06-10 10:00']);
});
