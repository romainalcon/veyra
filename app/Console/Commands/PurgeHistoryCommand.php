<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:history:purge')]
#[Description('Queue the batched retention cleanup of raw checks, dispatches and deliveries.')]
class PurgeHistoryCommand extends Command
{
    public function handle(): int
    {
        PurgeExpiredHistory::dispatch();

        return self::SUCCESS;
    }
}
