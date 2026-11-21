<?php

namespace App\Domain\Monitoring\Enums;

enum MonitorInterval: int
{
    case OneMinute = 60;
    case TwoMinutes = 120;
    case FiveMinutes = 300;
    case TenMinutes = 600;
    case FifteenMinutes = 900;
    case ThirtyMinutes = 1800;
    case OneHour = 3600;

    public function label(): string
    {
        return $this->value < 3600
            ? ($this->value / 60).' min'
            : '1 hour';
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $interval): array => ['value' => $interval->value, 'label' => $interval->label()],
            self::cases(),
        );
    }
}
