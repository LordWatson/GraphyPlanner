<?php

namespace App\Actions\Posts\Feedback;

use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.4 — resolve or re-open a feedback thread. Only a thread root is resolvable; resolving one
 * clears it from the post's unresolved-feedback count (which gates approval).
 */
class ResolvePostCommentAction
{
    public function __invoke(PostComment $comment, User $user, bool $resolved): PostComment
    {
        $comment->update([
            'resolved_at' => $resolved ? now() : null,
            'resolved_by_user_id' => $resolved ? $user->id : null,
        ]);

        Log::info($resolved ? 'Feedback thread resolved' : 'Feedback thread re-opened', [
            'post_id' => $comment->post_id,
            'post_comment_id' => $comment->id,
            'user_id' => $user->id,
        ]);

        return $comment;
    }
}
