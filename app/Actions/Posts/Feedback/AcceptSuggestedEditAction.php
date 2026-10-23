<?php

namespace App\Actions\Posts\Feedback;

use App\Actions\Posts\UpdatePostAction;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-6.4 — accept a reviewer's suggested caption edit in one click: the suggestion replaces the
 * post's master caption (recording a revision, like any content change), the suggestion is marked
 * resolved, and its thread is resolved. Staff only — it changes the content.
 */
class AcceptSuggestedEditAction
{
    public function __construct(private readonly UpdatePostAction $updatePost = new UpdatePostAction) {}

    public function __invoke(PostComment $comment, User $user): PostComment
    {
        if ($comment->suggested_edit === null) {
            throw ValidationException::withMessages(['suggested_edit' => 'This comment has no suggested edit to accept.']);
        }

        if ($comment->suggestion_resolved_at !== null) {
            throw ValidationException::withMessages(['suggested_edit' => 'This suggestion has already been dealt with.']);
        }

        return DB::transaction(function () use ($comment, $user) {
            $post = $comment->post;

            ($this->updatePost)($post, ['master_caption' => $comment->suggested_edit], $user);

            $comment->update([
                'suggestion_resolved_at' => now(),
                'resolved_at' => now(),
                'resolved_by_user_id' => $user->id,
            ]);

            Log::info('Suggested caption edit accepted', [
                'post_id' => $post->id,
                'post_comment_id' => $comment->id,
                'user_id' => $user->id,
            ]);

            return $comment;
        });
    }

    /**
     * Dismiss a suggestion without applying it (the caption is left as-is), resolving the thread.
     */
    public function dismiss(PostComment $comment, User $user): PostComment
    {
        $comment->update([
            'suggestion_resolved_at' => now(),
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
        ]);

        Log::info('Suggested caption edit dismissed', [
            'post_id' => $comment->post_id,
            'post_comment_id' => $comment->id,
            'user_id' => $user->id,
        ]);

        return $comment;
    }
}
