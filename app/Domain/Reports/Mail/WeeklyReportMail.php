<?php

namespace App\Domain\Reports\Mail;

use App\Domain\Notifications\Channels\NotificationMessage;
use App\Domain\Reports\WeeklyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Weekly report e-mail, in the organization language and time zone. It
 * carries a plain WeeklyReport, never a model.
 */
class WeeklyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly WeeklyReport $report)
    {
        $this->locale($report->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('[Veyra] Weekly report — :organization, :period', [
            'organization' => $this->report->organizationName,
            'period' => $this->report->periodLabel,
        ], $this->report->locale));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-report', with: [
            'report' => $this->report,
            'downtime' => NotificationMessage::humanDuration($this->report->downtimeSeconds, $this->report->locale),
            'dashboardUrl' => route('organizations.dashboard', $this->report->organizationSlug),
            'settingsUrl' => route('organizations.edit', $this->report->organizationSlug),
        ]);
    }

    /**
     * Markdown table cells: pipes and line breaks would break the layout.
     */
    public static function cell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\|', ' ', ' '], $value);
    }

    public static function percent(?float $value): string
    {
        return $value === null ? (string) __('No data') : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').' %';
    }
}
