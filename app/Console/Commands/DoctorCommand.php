<?php

namespace App\Console\Commands;

use App\Domain\System\CheckResult;
use App\Domain\System\ConfigurationDoctor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:doctor {--json : Output the report as JSON}')]
#[Description('Check that this instance is configured for production.')]
class DoctorCommand extends Command
{
    public function handle(ConfigurationDoctor $doctor): int
    {
        $results = $doctor->run();
        $failures = array_filter($results, fn (CheckResult $result): bool => $result->status === CheckResult::FAIL);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (CheckResult $result): array => $result->toArray(), $results), JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Detail'], array_map(fn (CheckResult $result): array => [
                $result->name,
                match ($result->status) {
                    CheckResult::OK => '<fg=green>ok</>',
                    CheckResult::WARN => '<fg=yellow>warn</>',
                    default => '<fg=red>FAIL</>',
                },
                $result->detail,
            ], $results));

            $failures === []
                ? $this->components->info('No blocking issue found.')
                : $this->components->error(count($failures).' blocking issue(s) found.');
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
