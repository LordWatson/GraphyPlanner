<?php

namespace App\Notifications;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-4.3 — tells the agency's billing users that this cycle's retainer invoices have been
 * drafted and are waiting to be reviewed, finalized and sent. Dispatched once per org per run from
 * `GenerateRetainerDraftsAction`.
 */
class RetainerDraftsReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $count) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS];
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
        $plural = $this->count === 1 ? 'retainer draft is' : 'retainer drafts are';

        return [
            'title' => 'Retainer drafts ready',
            'body' => "{$this->count} {$plural} ready to review and send",
            'url' => route('invoices.index', ['status' => 'draft']),
        ];
    }
}
