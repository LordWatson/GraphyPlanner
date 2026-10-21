<?php

namespace App\Mail;

use App\Actions\Analytics\GenerateClientReportPdfAction;
use App\Models\ClientReport;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step M-3.3 — the client's monthly report: a short note with the headline figures and the
 * agency's commentary, a link to the portal page, and the branded PDF attached (rendered from the
 * frozen snapshot when the queued mail is built). No billing data.
 */
class ClientReportPublishedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ClientReport $report, public ?string $contactName = null)
    {
        $this->onQueue(Queues::MAIL);
    }

    public function build(GenerateClientReportPdfAction $pdf): self
    {
        $studio = $this->report->organization->name ?? 'Your studio';

        return $this
            ->subject("Your {$this->report->periodLabel()} social report from {$studio}")
            ->view('emails.client-report-published', [
                'snapshot' => $this->report->snapshot ?? [],
                'studio' => $studio,
                'url' => route('portal.reports.show', $this->report),
            ])
            ->attachData($pdf($this->report), GenerateClientReportPdfAction::filename($this->report), [
                'mime' => 'application/pdf',
            ]);
    }
}
