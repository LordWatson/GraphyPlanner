<?php

namespace App\Mail;

use App\Actions\Campaigns\GenerateCampaignReportPdfAction;
use App\Models\Campaign;
use App\Models\CampaignReport;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Step C-7.3 — the shared wrap report: a short note linking to the portal page, with the branded
 * PDF attached (rendered when the queued mail is built, from the frozen snapshot).
 */
class CampaignReportSharedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public CampaignReport $report,
        public User $contact,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(GenerateCampaignReportPdfAction $pdf): self
    {
        return $this
            ->subject("How {$this->campaign->name} went")
            ->view('emails.campaign-report-shared', [
                'url' => route('portal.campaigns.show', $this->campaign).'#results',
            ])
            ->attachData($pdf($this->campaign, $this->report), Str::slug($this->campaign->name).'-wrap-report.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
