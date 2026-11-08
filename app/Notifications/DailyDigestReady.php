<?php

namespace App\Notifications;

use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Step W-2.6 — the day's updates in one WhatsApp message instead of several.
 *
 * **Not** a `ChatNotifier` implementation, which is what the plan proposed. That seam posts one
 * message to an organization's team channel and is addressed to an `Organization`; a digest is
 * addressed to a *person*, with their own preferences and their own verified number. Sending it
 * through the seam would have meant inventing a way to get from an organization back to a member,
 * which is exactly what the notification channel already does. `SlackChatNotifier` keeps the seam.
 *
 * WhatsApp only: the digest email is the digest. This is the nudge that it is worth reading.
 */
class DailyDigestReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $count) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return WhatsAppRouting::with([], $notifiable, static::class);
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::DailyDigest->fill([
            'summary' => $this->count.' '.Str::plural('update', $this->count).' since yesterday',
            'url' => route('notifications.index'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Daily digest',
            'body' => $this->count.' '.Str::plural('update', $this->count).' since yesterday',
            'url' => route('notifications.index'),
        ];
    }
}
