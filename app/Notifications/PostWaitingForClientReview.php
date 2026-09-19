<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies client-portal users (`Role::ClientReviewer`) that a post is ready for their review —
 * dispatched from `TransitionPostStatusAction` whenever a post lands in `waiting_client`,
 * alongside the existing `ClientReviewRequestedMail` email.
 */
class PostWaitingForClientReview extends Notification
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
            'title' => 'Post ready for review',
            'body' => 'A post is waiting for your review',
            'url' => route('portal.posts.show', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
