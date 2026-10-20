<?php

namespace App\Notifications;

use App\Models\GridComment;
use App\Models\GridPlan;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step 18.7 — a new grid comment: `mentioned` for each `@`-mentioned user, otherwise the "other
 * side" of the conversation (the team when the client comments; the client when the team posts a
 * non-internal comment). Same audiences as the post comment notifications.
 */
class GridCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly GridPlan $plan,
        private readonly GridComment $comment,
        private readonly bool $forPortal,
        private readonly bool $mentioned = false,
    ) {}

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
        $author = $this->comment->authorName();

        return [
            'title' => $this->mentioned ? 'You were mentioned' : 'New comment on a grid',
            'body' => $this->mentioned
                ? "{$author} mentioned you on the grid “{$this->plan->name}”"
                : "{$author} commented on the grid “{$this->plan->name}”",
            'url' => $this->forPortal ? route('portal.grids.show', $this->plan) : route('grids.edit', $this->plan),
            'grid_plan_id' => $this->plan->id,
        ];
    }
}
