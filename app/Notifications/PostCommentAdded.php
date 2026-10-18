<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\PostComment;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies a user that a new (visible-to-them) comment was added to a post they have a stake in.
 * Dispatched from `CreatePostCommentAction` to org users (when the commenter is a client
 * reviewer) or client-portal users (when the commenter is an org user and the comment isn't
 * `internal_only`) — never to the commenter themselves.
 */
class PostCommentAdded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly PostComment $comment,
        private readonly bool $forPortal,
    ) {}

    /**
     * Step I-2.8: bell notifications and mail each run on their own queue.
     *
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
            'title' => 'New comment',
            'body' => sprintf(
                '%s commented on a post for %s',
                $this->comment->user?->name ?? 'Someone',
                $this->post->client?->name ?? 'a client',
            ),
            'url' => $this->forPortal
                ? route('portal.posts.show', $this->post)
                : route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
