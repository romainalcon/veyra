<?php

namespace App\Console\Commands;

use App\Domain\StatusPages\Actions\VerifyPendingDomains;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:status-domains:verify')]
#[Description('Verify pending custom status page domains and re-check verified ones.')]
class VerifyStatusPageDomainsCommand extends Command
{
    public function handle(VerifyPendingDomains $verifyPendingDomains): int
    {
        $result = $verifyPendingDomains->handle();

        $this->components->info(sprintf('Checked %d domain(s), %d verified.', $result['checked'], $result['verified']));

        return self::SUCCESS;
    }
}
