<?php

namespace App\Notifications;

use App\Models\InboxConversation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-9.2 — tells the assignee (or the studio) that a comment has waited past the inbox reply
 * SLA, so someone answers it. Sent by `SendOverdueInboxRemindersJob`, once per day per thread.
 * Falls into the `Comments` notification category (M-6.7), so a user who muted comments isn't nudged.
 */
class InboxReplyOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly InboxConversation $conversation) {}

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
        $who = $this->conversation->author_name ?? 'Someone';

        return [
            'title' => 'A comment is waiting on a reply',
            'body' => "{$who} commented on {$this->conversation->platform->label()} and hasn't heard back past your inbox SLA.",
            'url' => route('inbox.index', ['client' => $this->conversation->client_id]),
            'inbox_conversation_id' => $this->conversation->id,
        ];
    }
}
