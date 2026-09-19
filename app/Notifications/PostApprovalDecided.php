<?php

namespace App\Notifications;

use App\Enums\ApprovalDecision;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies org users that a client approved or requested changes on a post — dispatched from
 * `TransitionPostStatusAction` whenever the destination status is `approved`/`changes_requested`
 * and the acting user is a `Role::ClientReviewer` (or the unauthenticated review portal, which
 * passes a null user).
 */
class PostApprovalDecided extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly ApprovalDecision $decision,
    ) {}

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
        $client = $this->post->client?->name ?? 'A client';

        return [
            'title' => $this->decision === ApprovalDecision::Approved
                ? 'Post approved'
                : 'Changes requested',
            'body' => $this->decision === ApprovalDecision::Approved
                ? "{$client} approved a post"
                : "{$client} requested changes on a post",
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
