<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\PostComment;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies a specific user that they were `@`-mentioned in a post comment. Dispatched from
 * `CreatePostCommentAction` for every mention token resolved to a real, mentionable user
 * (org user or client-portal user) — separate from `PostCommentAdded`, which notifies the
 * "other side" of the conversation regardless of mentions.
 */
class PostCommentMentioned extends Notification implements ShouldQueue
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
            'title' => 'You were mentioned',
            'body' => sprintf(
                '%s mentioned you in a comment on a post for %s',
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
