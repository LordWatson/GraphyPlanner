<?php

namespace App\Notifications;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies a specific org teammate that they were `@`-mentioned ("tagged") in the note attached to
 * a post status change — the dialog shown when someone moves a post to a different status, or the
 * Owner's approval-override reason (both land on the same transition note). Dispatched from
 * `TransitionPostStatusAction` for every mention token resolved to a real org recipient, pulling a
 * tagged teammate in to look at the post.
 *
 * The `@`-mention mechanism is shared with post comments (`PostCommentMentioned`); unlike comments,
 * status-dialog tags are internal, so this is scoped to org users only and always links to the
 * internal editor (`posts.edit`) — a client-portal user is never tagged this way.
 */
class PostNoteMentioned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly PostStatus $to,
        private readonly User $actor,
    ) {}

    /**
     * Bell notifications and mail each run on their own queue (Step I-2.8).
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
            'title' => 'You were tagged on a post',
            'body' => sprintf(
                '%s tagged you moving a post for %s to %s',
                $this->actor->name,
                $this->post->client->name,
                $this->to->label(),
            ),
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
