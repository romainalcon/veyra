<?php

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\StatusPages\Enums\PublicComponentStatus;

test('confirmed states map to public wording; unknown is never operational', function (MonitorStatus $state, PublicComponentStatus $public) {
    expect(PublicComponentStatus::fromMonitorStatus($state))->toBe($public);
})->with([
    [MonitorStatus::Up, PublicComponentStatus::Operational],
    [MonitorStatus::Degraded, PublicComponentStatus::Degraded],
    [MonitorStatus::Down, PublicComponentStatus::Outage],
    [MonitorStatus::Maintenance, PublicComponentStatus::Maintenance],
    [MonitorStatus::Unknown, PublicComponentStatus::NoData],
]);

test('the overall status is the worst one among components with data', function (array $statuses, PublicComponentStatus $expected) {
    expect(PublicComponentStatus::overall($statuses))->toBe($expected);
})->with([
    'empty' => [[], PublicComponentStatus::NoData],
    'only no data' => [[PublicComponentStatus::NoData, PublicComponentStatus::NoData], PublicComponentStatus::NoData],
    'no data does not mask operational' => [[PublicComponentStatus::NoData, PublicComponentStatus::Operational], PublicComponentStatus::Operational],
    'outage wins' => [[PublicComponentStatus::Operational, PublicComponentStatus::Outage, PublicComponentStatus::Degraded], PublicComponentStatus::Outage],
    'maintenance above operational' => [[PublicComponentStatus::Operational, PublicComponentStatus::Maintenance], PublicComponentStatus::Maintenance],
]);
