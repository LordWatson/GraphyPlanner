<?php

namespace App\Notifications\Campaigns;

use App\Models\User;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
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
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL, 'whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $notifiable instanceof User ? ['database'] : [];

        return WhatsAppRouting::with($channels, $notifiable, static::class);
    }

    /**
     * Step W-2.5 — every campaign notification can reach WhatsApp through the generic template,
     * built from the same `title`/`body`/`url` it puts in the bell. A subclass with copy worth
     * approving of its own (`CampaignBriefReady`) overrides this.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::Notification->fill([
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url($notifiable),
        ]);
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
