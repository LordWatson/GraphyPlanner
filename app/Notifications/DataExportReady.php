<?php

namespace App\Notifications;

use App\Models\DataExport;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The organisation's data export has finished and is downloadable until it expires.
 *
 * Deliberately **no mail channel and no link in an email**: the archive is the whole organisation's
 * records, so the way to it is through the signed-in settings page, not a URL sitting in an inbox.
 * Falls into the `Other` notification category, which is always on — somebody who asked for an
 * export must hear that it is ready.
 */
class DataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly DataExport $export) {}

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
        $days = (int) config('exports.retention_days', 7);

        return [
            'title' => 'Your data export is ready',
            'body' => "Everything we hold for your organisation, as one archive. It stays downloadable for {$days} ".($days === 1 ? 'day' : 'days').'.',
            'url' => route('data-export.index'),
            'data_export_id' => $this->export->id,
        ];
    }
}
