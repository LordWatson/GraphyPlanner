<?php

namespace App\Notifications;

use App\Models\Post;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-6.5 (Phase 9) — tells the studio (Owner/Strategist) that a post has sat past the approval
 * SLA, so someone chases it. Sent by `SendOverdueApprovalRemindersJob`, once per day per post.
 */
class ApprovalOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return WhatsAppRouting::with(['database'], $notifiable, static::class);
    }

    /**
     * Step W-2.5 — the nudge that stops an approval sitting for a week.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        $data = $this->toArray($notifiable);

        return MessageTemplate::ApprovalOverdue->fill([
            'post_summary' => $this->post->title ?? 'a post',
            'waiting_for' => (string) $data['body'],
            'url' => (string) $data['url'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $waiting = $this->post->status->value === 'waiting_client';

        return [
            'title' => 'Approval overdue',
            'body' => $waiting
                ? 'A post has been waiting on the client past your approval SLA.'
                : 'A post has had changes requested past your approval SLA.',
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
