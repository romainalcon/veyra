<?php

namespace App\Domain\Notifications\Enums;

enum NotificationEvent: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
    case Reminder = 'reminder';
    /** Manual "send test" from the channel settings (no incident). */
    case Test = 'test';

    public function headline(): string
    {
        return match ($this) {
            self::Opened => 'DOWN',
            self::Resolved => 'RESOLVED',
            self::Acknowledged => 'ACKNOWLEDGED',
            self::Reminder => 'STILL DOWN',
            self::Test => 'TEST',
        };
    }

    /**
     * Discord embed colour.
     */
    public function color(): int
    {
        return match ($this) {
            self::Opened, self::Reminder => 0xDC2626,
            self::Resolved => 0x16A34A,
            self::Acknowledged => 0xD97706,
            self::Test => 0x6B7280,
        };
    }
}
