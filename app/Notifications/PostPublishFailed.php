<?php

namespace App\Notifications;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies org users that a post failed to publish to one or more targets — dispatched from
 * `ResolvePostPublishOutcomeAction` whenever the post ends up `failed` or `partially_published`.
 */
class PostPublishFailed extends Notification implements ShouldQueue
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
            'title' => $this->post->status === PostStatus::PartiallyPublished ? 'Publish partly failed' : 'Publish failed',
            'body' => sprintf(
                $this->post->status === PostStatus::PartiallyPublished
                    ? 'A post for %s went out on some channels but failed on others'
                    : 'A post for %s failed to publish',
                $this->post->client?->name ?? 'a client',
            ),
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
