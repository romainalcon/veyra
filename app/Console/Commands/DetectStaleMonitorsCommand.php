<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Actions\DetectStaleMonitors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:monitors:detect-stale')]
#[Description('Mark monitors without recent results as UNKNOWN (never DOWN).')]
class DetectStaleMonitorsCommand extends Command
{
    public function handle(DetectStaleMonitors $detectStaleMonitors): int
    {
        $this->components->info(sprintf('Marked %d monitor(s) as unknown.', $detectStaleMonitors->handle()));

        return self::SUCCESS;
    }
}
