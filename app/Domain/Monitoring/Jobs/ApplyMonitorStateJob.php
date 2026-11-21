<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Actions\ApplyMonitorState;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * State transitions run on the `incidents` queue. Only one pending job per
 * monitor is kept (the lock is released when processing starts, so a check
 * recorded meanwhile triggers a fresh evaluation).
 */
class ApplyMonitorStateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 15];

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $monitorId)
    {
        $this->onQueue('incidents');
    }

    public function uniqueId(): string
    {
        return $this->monitorId;
    }

    public function handle(ApplyMonitorState $applyMonitorState): void
    {
        $applyMonitorState->handle($this->monitorId);
    }
}
