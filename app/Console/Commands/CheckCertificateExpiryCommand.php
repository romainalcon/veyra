<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Certificates\CheckCertificateExpiry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:certificates:check')]
#[Description('Warn the channels of HTTPS monitors whose TLS certificate is about to expire or has expired.')]
class CheckCertificateExpiryCommand extends Command
{
    public function handle(CheckCertificateExpiry $checkCertificateExpiry): int
    {
        $this->components->info(sprintf('Raised %d certificate alert(s).', $checkCertificateExpiry->handle()));

        return self::SUCCESS;
    }
}
