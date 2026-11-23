<?php

namespace App\Domain\System\Site;

/**
 * Where a roadmap milestone stands. Shipped work leaves the roadmap for the
 * changelog.
 */
enum RoadmapStatus: string
{
    case InProgress = 'in_progress';
    case Planned = 'planned';
    case Exploring = 'exploring';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => __('In progress'),
            self::Planned => __('Planned'),
            self::Exploring => __('Exploring'),
        };
    }
}
