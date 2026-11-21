<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\SendIncidentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:incidents:remind')]
#[Description('Send a single reminder for open incidents nobody acknowledged.')]
class SendIncidentRemindersCommand extends Command
{
    public function handle(SendIncidentReminders $sendIncidentReminders): int
    {
        $this->components->info(sprintf('Queued reminders for %d incident(s).', $sendIncidentReminders->handle()));

        return self::SUCCESS;
    }
}
