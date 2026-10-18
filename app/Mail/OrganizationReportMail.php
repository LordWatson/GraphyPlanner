<?php

namespace App\Mail;

use App\Models\OrganizationReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Step 17.8 — a scheduled organization report, emailed to one recipient (via Resend): headline
 * figures in the Alima voice, with the CSV and the branded PDF attached. Each recipient gets their
 * own run, so the figures and attachments only ever hold the sections *their* role may see.
 *
 * Sent synchronously by `SendScheduledOrganizationReportsAction` (the attachments are raw bytes,
 * which don't belong in a queue payload).
 */
class OrganizationReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public OrganizationReport $report,
        public User $recipient,
        public array $summary,
        public string $csv,
        public ?string $pdf = null,
    ) {}

    public function build(): self
    {
        $filename = Str::slug($this->report->name) ?: 'report';
        $date = now()->setTimezone($this->summary['filters']['timezone'] ?? 'UTC')->format('Y-m-d');

        $mail = $this
            ->subject("{$this->report->name} — your {$this->scheduleWord()} analytics")
            ->view('emails.organization-report', [
                'reportName' => $this->report->name,
                'organizationName' => $this->summary['organization']['name'] ?? '',
                'recipientName' => Str::before($this->recipient->name, ' '),
                'summary' => $this->summary,
                'reportUrl' => route('analytics.reports.show', $this->report),
            ])
            ->attachData($this->csv, "{$filename}-{$date}.csv", ['mime' => 'text/csv']);

        if ($this->pdf !== null) {
            $mail->attachData($this->pdf, "{$filename}-{$date}.pdf", ['mime' => 'application/pdf']);
        }

        return $mail;
    }

    private function scheduleWord(): string
    {
        return $this->report->schedule?->value === 'monthly' ? 'monthly' : 'weekly';
    }
}
