<?php

namespace App\Notifications;

use App\Models\ClientReport;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-3.3 — a client's monthly report draft is ready for the team to add commentary and publish.
 */
class ClientReportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ClientReport $report) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Monthly report ready to review',
            'body' => sprintf('%s’s %s report is drafted. Add your commentary and publish it.', $this->report->client->name ?? 'A client', $this->report->periodLabel()),
            'url' => route('client-reports.show', $this->report),
            'client_report_id' => $this->report->id,
        ];
    }
}
