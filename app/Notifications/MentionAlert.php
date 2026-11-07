<?php

namespace App\Notifications;

use App\Models\InboxConversation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A watched word just appeared in a comment. Sent once per match by `MentionAlerts`, to the studio
 * (Owner/Strategist). Falls into the `Comments` category (M-6.7) — the class name carries
 * "Mention", which is how `NotificationCategory::forNotificationClass` buckets it — so somebody who
 * muted comments is not nudged.
 *
 * Carries the term and who said it, not the comment body: the body is one click away in the inbox,
 * and a notification row is the wrong place to copy public text into.
 */
class MentionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly InboxConversation $conversation,
        private readonly string $term,
    ) {}

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
            'title' => "“{$this->term}” was mentioned",
            'body' => "{$who} used it in a comment on {$this->conversation->platform->label()}.",
            'url' => route('inbox.index', ['client' => $this->conversation->client_id]),
            'inbox_conversation_id' => $this->conversation->id,
            'term' => $this->term,
        ];
    }
}
