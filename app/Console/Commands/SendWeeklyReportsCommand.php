<?php

namespace App\Console\Commands;

use App\Domain\Reports\Actions\ScheduleWeeklyReports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:reports:weekly')]
#[Description('Queue the due weekly report e-mails (Monday morning, organization time zone).')]
class SendWeeklyReportsCommand extends Command
{
    public function handle(ScheduleWeeklyReports $scheduleWeeklyReports): int
    {
        $this->components->info(sprintf('Queued %d weekly report(s).', $scheduleWeeklyReports->handle()));

        return self::SUCCESS;
    }
}
