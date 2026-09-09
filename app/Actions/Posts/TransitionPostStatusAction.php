<?php

namespace App\Actions\Posts;

use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransitionPostStatusAction
{
    /**
     * Move a post to a new status, guarded by `PostStatusTransitionMap`. Always records an
     * activity log entry; also records an approval record when the destination status is a
     * client/reviewer decision (`approved` or `changes_requested`).
     *
     * Callers must authorize the transition (via `PostPolicy::transition`) before invoking this
     * action — it only enforces that the transition is a legal state-machine move.
     *
     * `$user` is nullable to support the unauthenticated Step 0.13 review portal (a client reviewer
     * acting via a `ReviewToken` rather than a logged-in session) — the resulting activity log/
     * approval rows simply record a null `user_id` in that case.
     */
    public function __invoke(
        Post $post,
        PostStatus $to,
        ?User $user,
        ?string $comment = null,
        EvaluatePostChecklistAction $evaluateChecklist = new EvaluatePostChecklistAction,
        SendClientReviewRequestedEmailAction $sendClientReviewRequestedEmail = new SendClientReviewRequestedEmailAction,
    ): Post {
        if (! PostStatusTransitionMap::isAllowed($post->status, $to)) {
            throw new \InvalidArgumentException(
                "Cannot transition post {$post->id} from {$post->status->value} to {$to->value}."
            );
        }

        return DB::transaction(function () use ($post, $to, $user, $comment, $evaluateChecklist, $sendClientReviewRequestedEmail) {
            $from = $post->status;

            // Keep the §6 checklist snapshot fresh on every transition, so the editor UI can
            // always render the last-evaluated state without a separate round-trip.
            $post->update([
                'status' => $to,
                'checklist_snapshot' => $evaluateChecklist($post),
            ]);

            $post->activityLogs()->create([
                'user_id' => $user?->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $comment,
            ]);

            if (in_array($to, [PostStatus::Approved, PostStatus::ChangesRequested], true)) {
                $post->approvals()->create([
                    'user_id' => $user?->id,
                    'decision' => $to === PostStatus::Approved
                        ? ApprovalDecision::Approved
                        : ApprovalDecision::ChangesRequested,
                    'comment' => $comment,
                ]);
            }

            Log::info('Post status transitioned', [
                'post_id' => $post->id,
                'user_id' => $user?->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);

            // Step 0.13: notify the client by email (via Resend) with the review-portal link
            // whenever a post lands in `waiting_client`, so they always have a fresh, valid token.
            if ($to === PostStatus::WaitingClient) {
                $sendClientReviewRequestedEmail($post);
            }

            return $post->refresh();
        });
    }
}
