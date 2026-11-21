<?php

namespace App\Console\Commands;

use App\Domain\System\SchedulerBeatLog;
use App\Domain\System\SchedulerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:heartbeat')]
#[Description('Record that the scheduler is running (used by /health, the monitoring banner and heartbeat evaluation).')]
class HeartbeatCommand extends Command
{
    public function handle(): int
    {
        SchedulerHeartbeat::beat();
        SchedulerBeatLog::record();

        return self::SUCCESS;
    }
}
