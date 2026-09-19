<?php

namespace App\Actions\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Notifications\PostCommentAdded;
use App\Services\PostNotificationService;
use Illuminate\Support\Facades\Log;

class CreatePostCommentAction
{
    public function __construct(private readonly PostNotificationService $notifications = new PostNotificationService) {}

    /**
     * Add a comment to a post. Client reviewers can never write an internal-only comment,
     * regardless of what's submitted — internal notes must stay invisible to clients.
     */
    public function __invoke(Post $post, User $user, string $body, bool $internalOnly = false): PostComment
    {
        $comment = $post->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'internal_only' => $user->role === Role::ClientReviewer ? false : $internalOnly,
        ]);

        Log::info('Post comment created', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'internal_only' => $comment->internal_only,
        ]);

        $this->notifyOtherSide($post, $comment, $user);

        return $comment;
    }

    /**
     * Notify whichever side of the conversation didn't write this comment — org users when a
     * client reviewer comments, or (unless internal-only) client-portal users when an org user
     * comments — so a new comment surfaces in the notification bell for the people who need to
     * see it.
     */
    private function notifyOtherSide(Post $post, PostComment $comment, User $author): void
    {
        if ($author->role === Role::ClientReviewer) {
            $this->notifications->send(
                $this->notifications->orgRecipients($post),
                new PostCommentAdded($post, $comment, forPortal: false),
            );

            return;
        }

        if ($comment->internal_only) {
            return;
        }

        $this->notifications->send(
            $this->notifications->clientPortalRecipients($post),
            new PostCommentAdded($post, $comment, forPortal: true),
        );
    }
}
