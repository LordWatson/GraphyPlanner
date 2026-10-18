<?php

namespace App\Notifications;

use App\Models\Post;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies the studio's org users that a post is ready for internal review — dispatched from
 * `TransitionPostStatusAction` whenever a post lands in `internal_review`, so someone picks it up
 * before it goes to the client. In-app (database) only; no email.
 */
class PostWaitingForInternalReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

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
            'title' => 'Post ready for internal review',
            'body' => sprintf('A post for %s is waiting for internal review', $this->post->client->name),
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
