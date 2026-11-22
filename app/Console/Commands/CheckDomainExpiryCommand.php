<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Jobs\CheckDomainExpiriesJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:domains:check')]
#[Description('Queue the RDAP refresh of monitored domains (at most once a day each) and their expiry warnings.')]
class CheckDomainExpiryCommand extends Command
{
    public function handle(): int
    {
        if (! (bool) config('veyra.domains.enabled', true)) {
            $this->components->info('Domain expiry checks are disabled (VEYRA_DOMAIN_EXPIRY_ENABLED).');

            return self::SUCCESS;
        }

        CheckDomainExpiriesJob::dispatch();
        $this->components->info('Domain expiry check queued.');

        return self::SUCCESS;
    }
}
