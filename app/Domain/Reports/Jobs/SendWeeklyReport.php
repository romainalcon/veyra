<?php

namespace App\Domain\Reports\Jobs;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Actions\BuildWeeklyReport;
use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Builds one organization's weekly report and queues one e-mail per
 * opted-in member with a verified address. `weekly_reports.queued_at` is set
 * in the same transaction as the e-mails are queued (after commit), so a
 * retry after that point sends nothing again; a failure before it retries
 * the whole report.
 */
class SendWeeklyReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $reportId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->reportId;
    }

    public function handle(BuildWeeklyReport $buildWeeklyReport): void
    {
        $row = DB::table('weekly_reports')->where('id', $this->reportId)->first();

        if ($row === null || $row->queued_at !== null) {
            return;
        }

        $organization = Organization::query()->find((string) $row->organization_id);

        if ($organization === null) {
            return;
        }

        $report = $buildWeeklyReport->handle($organization, CarbonImmutable::parse($this->periodStart), CarbonImmutable::parse($this->periodEnd));

        DB::transaction(function () use ($organization, $report): void {
            $locked = DB::table('weekly_reports')->where('id', $this->reportId)->lockForUpdate()->first();

            if ($locked === null || $locked->queued_at !== null) {
                return;
            }

            $recipients = User::query()
                ->whereNotNull('email_verified_at')
                ->whereHas('memberships', fn ($query) => $query
                    ->where('organization_id', $organization->id)
                    ->where('weekly_report', true))
                ->get(['id', 'email']);

            foreach ($recipients as $user) {
                Mail::to($user->email)->queue((new WeeklyReportMail($report))->onQueue('notifications')->afterCommit());
            }

            DB::table('weekly_reports')->where('id', $this->reportId)->update([
                'queued_at' => CarbonImmutable::now(),
                'recipients_count' => $recipients->count(),
            ]);
        });
    }
}
