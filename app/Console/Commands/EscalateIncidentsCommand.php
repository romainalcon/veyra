<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\EscalateIncidents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:incidents:escalate')]
#[Description('Notify the due escalation steps of open, unacknowledged incidents.')]
class EscalateIncidentsCommand extends Command
{
    public function handle(EscalateIncidents $escalateIncidents): int
    {
        $this->components->info(sprintf('Fired %d escalation step(s).', $escalateIncidents->handle()));

        return self::SUCCESS;
    }
}
