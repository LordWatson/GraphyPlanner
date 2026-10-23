<?php

namespace App\Support\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;

/**
 * Step M-6.4 — the post's visual-feedback threads (pins, caption-range notes and suggested edits)
 * shaped for the editor, the client portal and the review link. Internal-only threads are dropped
 * for a client view; every row carries the viewer's abilities so the UI shows the right controls.
 */
final class PostFeedbackPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function threads(Post $post, ?User $viewer, bool $forClient): array
    {
        $post->loadMissing(['comments.user', 'comments.replies.user', 'comments.resolvedBy']);

        $canManage = $viewer !== null && $viewer->role !== Role::ClientReviewer;
        $canAcceptSuggestion = $canManage && $viewer->can('update', $post);
        $canResolve = $viewer !== null && $viewer->can('comment', $post);

        return $post->comments
            ->whereNull('parent_id')
            ->when($forClient, fn ($threads) => $threads->where('internal_only', false))
            ->sortBy('id')
            ->map(fn (PostComment $comment) => [
                'id' => $comment->id,
                'author' => $comment->user?->name,
                'body' => $comment->body,
                'internal_only' => $comment->internal_only,
                'anchor_type' => $comment->anchor_type?->value,
                'anchor' => $comment->anchor,
                'suggested_edit' => $comment->suggested_edit,
                'suggestion_resolved' => $comment->suggestion_resolved_at !== null,
                'resolved' => $comment->isResolved(),
                'resolved_by' => $comment->resolvedBy?->name,
                'is_feedback' => $comment->isFeedback(),
                'created_at' => $comment->created_at?->toIso8601String(),
                'can' => [
                    'resolve' => $canResolve && $comment->isFeedback(),
                    'accept_suggestion' => $canAcceptSuggestion
                        && $comment->suggested_edit !== null
                        && $comment->suggestion_resolved_at === null,
                ],
                'replies' => $comment->replies
                    ->when($forClient, fn ($replies) => $replies->where('internal_only', false))
                    ->map(fn (PostComment $reply) => [
                        'id' => $reply->id,
                        'author' => $reply->user?->name,
                        'body' => $reply->body,
                        'created_at' => $reply->created_at?->toIso8601String(),
                    ])->values()->all(),
            ])->values()->all();
    }
}
