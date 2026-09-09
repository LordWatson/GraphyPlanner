<?php

namespace App\Actions\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CreatePostCommentAction
{
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

        return $comment;
    }
}
