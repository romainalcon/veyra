<?php

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\State\StateEvaluator;
use App\Domain\Monitoring\State\StateThresholds;

/**
 * @param  string  $results  newest first, e.g. "FFS" = fail, fail, success
 */
function evaluateState(MonitorStatus $current, string $results, ?StateThresholds $thresholds = null): MonitorStatus
{
    $flags = array_map(fn (string $char): bool => $char === 'S', str_split($results));

    return StateEvaluator::evaluate($current, $results === '' ? [] : $flags, $thresholds ?? new StateThresholds)->status;
}

test('transitions follow the confirmation thresholds', function (MonitorStatus $current, string $results, MonitorStatus $expected) {
    expect(evaluateState($current, $results))->toBe($expected);
})->with([
    'no data keeps the state' => [MonitorStatus::Up, '', MonitorStatus::Up],
    'unknown needs two successes' => [MonitorStatus::Unknown, 'S', MonitorStatus::Unknown],
    'unknown to up' => [MonitorStatus::Unknown, 'SS', MonitorStatus::Up],
    'unknown stays unknown on isolated failures' => [MonitorStatus::Unknown, 'FF', MonitorStatus::Unknown],
    'unknown to down after three failures' => [MonitorStatus::Unknown, 'FFF', MonitorStatus::Down],
    'up stays up on success' => [MonitorStatus::Up, 'S', MonitorStatus::Up],
    'one failure degrades' => [MonitorStatus::Up, 'FS', MonitorStatus::Degraded],
    'two failures degrade' => [MonitorStatus::Up, 'FFS', MonitorStatus::Degraded],
    'three failures confirm down' => [MonitorStatus::Degraded, 'FFFS', MonitorStatus::Down],
    'degraded recovers on one success' => [MonitorStatus::Degraded, 'SFF', MonitorStatus::Up],
    'down stays down on one success' => [MonitorStatus::Down, 'SFFF', MonitorStatus::Down],
    'down recovers after two successes' => [MonitorStatus::Down, 'SSF', MonitorStatus::Up],
    'down stays down on failures' => [MonitorStatus::Down, 'FS', MonitorStatus::Down],
    'only the leading streak counts' => [MonitorStatus::Up, 'FFSFF', MonitorStatus::Degraded],
]);

test('counters reflect the leading streak', function () {
    $evaluation = StateEvaluator::evaluate(MonitorStatus::Up, [false, false, true, false], new StateThresholds);

    expect($evaluation->consecutiveFailures)->toBe(2)
        ->and($evaluation->consecutiveSuccesses)->toBe(0);
});

test('thresholds are configurable', function () {
    $strict = new StateThresholds(downAfter: 5, upAfter: 3);

    expect(evaluateState(MonitorStatus::Up, 'FFFF', $strict))->toBe(MonitorStatus::Degraded)
        ->and(evaluateState(MonitorStatus::Up, 'FFFFF', $strict))->toBe(MonitorStatus::Down)
        ->and(evaluateState(MonitorStatus::Down, 'SS', $strict))->toBe(MonitorStatus::Down)
        ->and(evaluateState(MonitorStatus::Down, 'SSS', $strict))->toBe(MonitorStatus::Up);
});

/**
 * @param  string  $results  newest first: "F" = fail, "S" = fast success, "L" = slow success
 */
function evaluateLatency(MonitorStatus $current, string $results): MonitorStatus
{
    $chars = str_split($results);

    return StateEvaluator::evaluate(
        $current,
        array_map(fn (string $char): bool => $char !== 'F', $chars),
        new StateThresholds,
        array_map(fn (string $char): bool => $char === 'L', $chars),
    )->status;
}

test('slow responses degrade after the usual thresholds and never confirm down', function (MonitorStatus $current, string $results, MonitorStatus $expected) {
    expect(evaluateLatency($current, $results))->toBe($expected);
})->with([
    'one slow response keeps up' => [MonitorStatus::Up, 'LSS', MonitorStatus::Up],
    'two slow responses keep up' => [MonitorStatus::Up, 'LLS', MonitorStatus::Up],
    'three slow responses degrade' => [MonitorStatus::Up, 'LLLS', MonitorStatus::Degraded],
    'many slow responses never go down' => [MonitorStatus::Degraded, 'LLLLLLLL', MonitorStatus::Degraded],
    'unknown to degraded when slow' => [MonitorStatus::Unknown, 'LLL', MonitorStatus::Degraded],
    'unknown to up on two slow responses' => [MonitorStatus::Unknown, 'LL', MonitorStatus::Up],
    'one fast response keeps degraded' => [MonitorStatus::Degraded, 'SLLL', MonitorStatus::Degraded],
    'two fast responses recover' => [MonitorStatus::Degraded, 'SSLLL', MonitorStatus::Up],
    'an isolated slow response keeps degraded' => [MonitorStatus::Degraded, 'LSLLL', MonitorStatus::Degraded],
    'slow responses recover from down' => [MonitorStatus::Down, 'LLFFF', MonitorStatus::Up],
    'down to degraded on a slow streak' => [MonitorStatus::Down, 'LLLFFF', MonitorStatus::Degraded],
    'failures still confirm down' => [MonitorStatus::Degraded, 'FFFLLL', MonitorStatus::Down],
    'failure degradation recovers on a fast response' => [MonitorStatus::Degraded, 'SFF', MonitorStatus::Up],
    'no slow flags: unchanged behaviour' => [MonitorStatus::Degraded, 'SFF', MonitorStatus::Up],
]);

test('slow successes count as successes in the streaks', function () {
    $evaluation = StateEvaluator::evaluate(MonitorStatus::Up, [true, true, true, false], new StateThresholds, [true, true, true, false]);

    expect($evaluation->status)->toBe(MonitorStatus::Degraded)
        ->and($evaluation->consecutiveSuccesses)->toBe(3)
        ->and($evaluation->consecutiveFailures)->toBe(0);
});
