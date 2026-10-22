<?php

namespace App\Actions\Posts;

use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\ReviewToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step B-3.4 — records what a "Share preview" viewer (a partner or sponsor) left through the link,
 * strictly within the token's granted capabilities. It **never** transitions the post: a business
 * post has no `waiting_client` stage, so a sponsor's sign-off is recorded (for the creator to see,
 * and for a future "require sponsor approval" per-post rule, B-11.1) but doesn't move it.
 *
 * Both writes land in one transaction. A comment is only recorded when the token allows comments;
 * a decision only when it allows approval — the controller re-checks too (defence in depth).
 */
class RecordPreviewFeedbackAction
{
    public function __invoke(ReviewToken $token, Post $post, ?PostStatus $decision, ?string $comment): void
    {
        DB::transaction(function () use ($token, $post, $decision, $comment) {
            if ($comment !== null && trim($comment) !== '' && $token->allow_comments) {
                $post->comments()->create([
                    'user_id' => null,
                    'body' => trim($comment),
                    'internal_only' => false,
                ]);
            }

            if ($decision !== null && $token->allow_approval) {
                $post->approvals()->create([
                    'user_id' => null,
                    'decision' => $decision === PostStatus::Approved
                        ? ApprovalDecision::Approved
                        : ApprovalDecision::ChangesRequested,
                    'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                ]);
            }
        });

        Log::info('Share preview feedback recorded', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'review_token_id' => $token->id,
            'decision' => $decision?->value,
            'has_comment' => $comment !== null && trim($comment) !== '',
        ]);
    }
}
