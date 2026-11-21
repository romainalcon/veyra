<?php

namespace App\Domain\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'veyra:scheduler:heartbeat';

    public const STALE_AFTER_SECONDS = 180;

    public static function beat(): void
    {
        Cache::forever(self::CACHE_KEY, CarbonImmutable::now()->getTimestamp());
    }

    public static function lastBeat(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }

    public static function isAlive(): bool
    {
        $last = self::lastBeat();

        return $last !== null && $last->greaterThan(CarbonImmutable::now()->subSeconds(self::STALE_AFTER_SECONDS));
    }
}
