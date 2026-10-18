<?php

namespace App\Notifications\Campaigns;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of every campaign bell notification (`title`, `body`, `url`, `campaign_id`), queued
 * on the notifications queue like the post notifications (Step I-2.8).
 */
abstract class CampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function campaignId(): int;

    abstract protected function title(): string;

    abstract protected function body(): string;

    abstract protected function url(object $notifiable): string;

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
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url($notifiable),
            'campaign_id' => $this->campaignId(),
        ];
    }
}
