<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies org users that a post failed to publish to one or more targets — dispatched from
 * `PublishPostJob` whenever the post ends up in `failed` status.
 */
class PostPublishFailed extends Notification
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

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
            'title' => 'Publish failed',
            'body' => sprintf(
                'A post for %s failed to publish',
                $this->post->client?->name ?? 'a client',
            ),
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
