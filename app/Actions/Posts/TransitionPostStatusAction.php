<?php

namespace App\Actions\Posts;

use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostWaitingForClientReview;
use App\Services\PostNotificationService;
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
        PostNotificationService $notifications = new PostNotificationService,
    ): Post {
        if (! PostStatusTransitionMap::isAllowed($post->status, $to)) {
            throw new \InvalidArgumentException(
                "Cannot transition post {$post->id} from {$post->status->value} to {$to->value}."
            );
        }

        $post = DB::transaction(function () use ($post, $to, $user, $comment, $evaluateChecklist, $sendClientReviewRequestedEmail) {
            $from = $post->status;

            // Keep the §6 checklist snapshot fresh on every transition, so the editor UI can
            // always render the last-evaluated state without a separate round-trip.
            $post->update([
                'status' => $to,
                'checklist_snapshot' => $evaluateChecklist($post),
            ]);

            // Re-queuing a previously `failed` post must give every target a clean slate —
            // otherwise the old vendor error/external id would keep showing next to the target
            // even though a fresh publish attempt is about to run (the error itself is preserved
            // on the activity log entry created below instead).
            if ($from === PostStatus::Failed && $to === PostStatus::Scheduled) {
                $post->targets()->update([
                    'status' => PostTargetStatus::Pending,
                    'external_post_id' => null,
                    'error' => null,
                ]);
            }

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
            // The resulting signed URL is recorded on the activity log below so an org user can
            // see it and re-send the email later without needing to re-check the client's inbox.
            $reviewUrl = $to === PostStatus::WaitingClient
                ? $sendClientReviewRequestedEmail($post)
                : null;

            $post->activityLogs()->create([
                'user_id' => $user?->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $comment,
                'review_url' => $reviewUrl,
            ]);

            return $post->refresh();
        });

        // Step 1.4: nothing calls the vendor until this point — the §6 checklist is already
        // re-validated server-side before `scheduled` is even a legal destination (see
        // `TransitionPostRequest`), so it's safe to enqueue the publish job right here.
        if ($to === PostStatus::Scheduled) {
            $this->dispatchPublishJob($post);
        }

        $this->notify($post, $to, $user, $notifications);

        return $post;
    }

    /**
     * Notify the interested audience for a transition: org users when a client decides
     * (approved/changes requested), client-portal users when a post lands in `waiting_client`.
     */
    private function notify(Post $post, PostStatus $to, ?User $user, PostNotificationService $notifications): void
    {
        if ($to === PostStatus::WaitingClient) {
            $notifications->send(
                $notifications->clientPortalRecipients($post),
                new PostWaitingForClientReview($post),
            );

            return;
        }

        if (! in_array($to, [PostStatus::Approved, PostStatus::ChangesRequested], true)) {
            return;
        }

        // Only a client decision (a null user via the review portal, or a logged-in
        // Role::ClientReviewer via the client portal) is notification-worthy here — an internal
        // org user moving a post straight to `approved` isn't a client action.
        if ($user !== null && $user->role !== Role::ClientReviewer) {
            return;
        }

        $decision = $to === PostStatus::Approved
            ? ApprovalDecision::Approved
            : ApprovalDecision::ChangesRequested;

        $notifications->send(
            $notifications->orgRecipients($post),
            new PostApprovalDecided($post, $decision),
        );
    }

    /**
     * Enqueue `PublishPostJob`, delayed until the earliest target's `scheduled_at_utc` if that
     * instant is still in the future (immediate dispatch otherwise, e.g. a past-due reschedule).
     */
    private function dispatchPublishJob(Post $post): void
    {
        $publishAt = $post->targets()->whereNotNull('scheduled_at_utc')->min('scheduled_at_utc');

        $job = PublishPostJob::dispatch($post->id);

        if ($publishAt && now()->lessThan($publishAt)) {
            $job->delay($publishAt);
        }

        Log::info('Publish job enqueued for scheduled post', [
            'post_id' => $post->id,
            'publish_at' => $publishAt,
        ]);
    }
}
