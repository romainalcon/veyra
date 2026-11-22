<?php

namespace App\Domain\StatusPages\Enums;

use App\Domain\Monitoring\Enums\MonitorStatus;

/**
 * Public wording of a confirmed monitor state. Missing data is "no data",
 * never "operational".
 */
enum PublicComponentStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Outage = 'outage';
    case Maintenance = 'maintenance';
    case NoData = 'no_data';

    public static function fromMonitorStatus(MonitorStatus $status): self
    {
        return match ($status) {
            MonitorStatus::Up => self::Operational,
            MonitorStatus::Degraded => self::Degraded,
            MonitorStatus::Down => self::Outage,
            MonitorStatus::Maintenance => self::Maintenance,
            MonitorStatus::Unknown => self::NoData,
        };
    }

    /**
     * English label (translation key), as shown on the public page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operational',
            self::Degraded => 'Degraded performance',
            self::Outage => 'Outage',
            self::Maintenance => 'Under maintenance',
            self::NoData => 'No data',
        };
    }

    /**
     * Higher is worse; used to derive the overall page status.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Outage => 40,
            self::Degraded => 30,
            self::Maintenance => 20,
            self::Operational => 10,
            self::NoData => 0,
        };
    }

    /**
     * The worst status among components with data; "no data" only when no
     * component has any.
     *
     * @param  list<self>  $statuses
     */
    public static function overall(array $statuses): self
    {
        $worst = self::NoData;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
