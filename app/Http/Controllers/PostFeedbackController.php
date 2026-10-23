<?php

namespace App\Http\Controllers;

use App\Actions\Posts\Feedback\AcceptSuggestedEditAction;
use App\Actions\Posts\Feedback\ResolvePostCommentAction;
use App\Models\PostComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Step M-6.4 — staff actions on a post's visual feedback: resolve / re-open a thread, and accept or
 * dismiss a reviewer's suggested caption edit. Thin: authorize, call the Action, flash, redirect.
 */
class PostFeedbackController extends Controller
{
    public function resolve(Request $request, PostComment $comment, ResolvePostCommentAction $action): RedirectResponse
    {
        $comment->loadMissing('post.client');
        $this->authorize('comment', $comment->post);
        abort_unless($comment->parent_id === null, 404);

        $action($comment, $request->user(), $request->boolean('resolved', true));

        return to_route('posts.edit', $comment->post_id);
    }

    public function acceptSuggestion(PostComment $comment, AcceptSuggestedEditAction $action): RedirectResponse
    {
        $comment->loadMissing('post.client');
        $this->authorize('update', $comment->post);

        $action($comment, request()->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suggestion applied to the caption.')]);

        return to_route('posts.edit', $comment->post_id);
    }

    public function dismissSuggestion(PostComment $comment, AcceptSuggestedEditAction $action): RedirectResponse
    {
        $comment->loadMissing('post.client');
        $this->authorize('update', $comment->post);

        $action->dismiss($comment, request()->user());

        return to_route('posts.edit', $comment->post_id);
    }
}
