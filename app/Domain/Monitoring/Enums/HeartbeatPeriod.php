<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Expected time between two pings of a heartbeat monitor.
 */
enum HeartbeatPeriod: int
{
    case OneMinute = 60;
    case FiveMinutes = 300;
    case TenMinutes = 600;
    case FifteenMinutes = 900;
    case ThirtyMinutes = 1800;
    case OneHour = 3600;
    case TwoHours = 7200;
    case SixHours = 21600;
    case TwelveHours = 43200;
    case OneDay = 86400;
    case OneWeek = 604800;

    public function label(): string
    {
        return match (true) {
            $this->value < 3600 => ($this->value / 60).' min',
            $this->value === 3600 => '1 hour',
            $this->value < 86400 => ($this->value / 3600).' hours',
            $this->value === 86400 => '1 day',
            default => '1 week',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $period): array => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }
}
