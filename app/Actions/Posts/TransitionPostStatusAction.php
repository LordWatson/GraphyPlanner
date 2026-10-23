<?php

namespace App\Actions\Posts;

use App\Actions\Campaigns\MarkCampaignWentLiveAction;
use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Jobs\PublishPostJob;
use App\Jobs\SendScheduledPostToVendorJob;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostNoteMentioned;
use App\Notifications\PostWaitingForClientReview;
use App\Notifications\PostWaitingForInternalReview;
use App\Services\PostNotificationService;
use App\Services\Posts\PostRevisionService;
use App\Services\Posts\PostWorkflowResolver;
use App\Services\Publishing\VendorScheduleService;
use App\Support\CommentMentionParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransitionPostStatusAction
{
    /** Step M-2.9: set only while `overrideApproval()` runs. */
    private bool $overriding = false;

    /**
     * Step I-3.8 / M-2.9 — an Owner approves a `client_required` post without the client. The
     * reason is required and lands on the approval record and the activity log ("Approval
     * override: …"), so it's always visible who skipped the client and why.
     */
    public function overrideApproval(Post $post, User $owner, string $reason): Post
    {
        $this->overriding = true;

        try {
            $result = $this($post, PostStatus::Approved, $owner, 'Approval override: '.trim($reason));
        } finally {
            $this->overriding = false;
        }

        Log::warning('Client approval overridden', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'user_id' => $owner->id,
        ]);

        return $result;
    }

    /**
     * Whether `$to` is a legal next status for `$post` right now. The override path is the one
     * Owner-only agency move that normal legality forbids (approving a `client_required` post
     * without the client); everything else is asked of the per-org-type workflow (Step B-3.1), so
     * a business post follows its own lifecycle while agency behaviour is unchanged.
     *
     * `$from` is always `$post->status` at every call site (the first check, and the re-check
     * against the row locked inside the transaction), so the workflow's `$post->status`-based
     * answer is correct for both.
     */
    private function allowed(Post $post, PostStatus $to, ?User $user = null): bool
    {
        if ($this->overriding) {
            return PostStatusTransitionMap::isAllowed($post->status, $to)
                && PostStatusTransitionMap::needsClientApproval($post, $post->status, $to);
        }

        return in_array($to, app(PostWorkflowResolver::class)->forPost($post)->allowedTransitions($post, $user), true);
    }

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
     *
     * Step I-1.4: the post row is locked and the move re-checked against the *locked* status, so
     * two simultaneous decisions (review link + portal) can't both pass — the loser gets an
     * `InvalidPostTransitionException` (409). Every side effect (client email, notifications, the
     * publish job) runs only after the outermost transaction commits.
     *
     * Step I-1.2: entering `scheduled` writes a fresh `publish_dispatch_id` and hands it to the
     * publish job; leaving `scheduled` clears it, so any job still queued for an older schedule
     * no-ops when it fires.
     *
     * Step I-3.8 / M-2.9: a `client_required` post can't go from internal review straight to
     * `approved` — only through the client, or an Owner's `overrideApproval()`.
     *
     * Step I-3.6 / M-2.5: `publishing → approved/draft` ("Unschedule" while every channel is still
     * held by the vendor) cancels those vendor-held publishes first, through
     * `VendorScheduleService::recall()`, and resets every channel to unsent. A `scheduled` post is
     * handed to the vendor as soon as it's scheduled, so leaving `scheduled` (other than for
     * `publishing`) does the same.
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
        if (! $this->allowed($post, $to, $user)) {
            throw new InvalidPostTransitionException($post->id, $post->status, $to);
        }

        // Step I-3.6 / M-2.5: unscheduling a `publishing` post cancels its vendor-held channels
        // before anything changes here — if the vendor says it's too late, nothing moves.
        if (PostStatusTransitionMap::isRecallMove($post->status, $to)) {
            if (! $post->canBeRecalled()) {
                throw ValidationException::withMessages([
                    'to' => __('Some channels have already gone out, so this post can’t be taken off the schedule.'),
                ]);
            }

            app(VendorScheduleService::class)->recall($post, 'to');
        }

        // A `scheduled` post is already with the vendor (`SendScheduledPostToVendorJob`), so
        // taking it off the schedule cancels those channels there first, the same way.
        if (PostStatusTransitionMap::leavesSchedule($post->status, $to) && $post->isHeldByVendor()) {
            app(VendorScheduleService::class)->recall($post, 'to');
        }

        $locked = DB::transaction(function () use ($post, $to, $user, $comment, $evaluateChecklist, $sendClientReviewRequestedEmail, $notifications) {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $this->allowed($locked, $to, $user)) {
                Log::warning('Post transition rejected: status changed concurrently', [
                    'post_id' => $locked->id,
                    'user_id' => $user?->id,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ]);

                throw new InvalidPostTransitionException($locked->id, $from, $to);
            }

            $dispatchId = match (true) {
                $to === PostStatus::Scheduled => (string) Str::uuid(),
                $from === PostStatus::Scheduled, PostStatusTransitionMap::isRecallMove($from, $to) => null,
                default => $locked->publish_dispatch_id,
            };

            // Keep the §6 checklist snapshot fresh on every transition, so the editor UI can
            // always render the last-evaluated state without a separate round-trip.
            $locked->update([
                'status' => $to,
                'publish_dispatch_id' => $dispatchId,
                'checklist_snapshot' => $evaluateChecklist($locked),
            ]);

            // Re-queuing a previously `failed` post must give every target a clean slate —
            // otherwise the old vendor error/external id would keep showing next to the target
            // even though a fresh publish attempt is about to run (the error itself is preserved
            // on the activity log entry created below instead).
            if (($from === PostStatus::Failed && $to === PostStatus::Scheduled) || PostStatusTransitionMap::isRecallMove($from, $to)
                || PostStatusTransitionMap::leavesSchedule($from, $to)) {
                $locked->targets()->where('status', '!=', PostTargetStatus::Published)->update([
                    'status' => PostTargetStatus::Pending,
                    'external_post_id' => null,
                    'error' => null,
                    'publish_attempt_id' => null,
                    'publish_requested_at' => null,
                ]);
            }

            // Unscheduling (scheduled → approved, C-6.1) keeps the existing approval; it isn't a
            // new decision, so no approval row.
            if (in_array($to, [PostStatus::Approved, PostStatus::ChangesRequested], true) && ! in_array($from, [PostStatus::Scheduled, PostStatus::Publishing], true)) {
                $locked->approvals()->create([
                    'user_id' => $user?->id,
                    'decision' => $to === PostStatus::Approved
                        ? ApprovalDecision::Approved
                        : ApprovalDecision::ChangesRequested,
                    'comment' => $comment,
                ]);
            }

            Log::info('Post status transitioned', [
                'post_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $user?->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);

            // Step 0.13: email the client the review-portal link whenever a post lands in
            // `waiting_client`, so they always have a fresh, valid token. Only the token's id is
            // recorded (Step I-1.6); the email itself is queued after commit.
            $reviewToken = $to === PostStatus::WaitingClient
                ? $sendClientReviewRequestedEmail($locked)
                : null;

            $locked->activityLogs()->create([
                'user_id' => $user?->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $comment,
                'review_token_id' => $reviewToken?->id,
            ]);

            $locked->refresh();

            // Step M-5.3: snapshot the version the client is shown, so a later re-review can show
            // "what changed since you last saw it".
            if ($to === PostStatus::WaitingClient) {
                app(PostRevisionService::class)->record($locked, $user, shared: true);
            }

            DB::afterCommit(function () use ($locked, $to, $user, $comment, $notifications) {
                // Step 1.4: nothing calls the vendor until this point — the §6 checklist is
                // already re-validated server-side before `scheduled` is even a legal
                // destination (see `TransitionPostRequest`).
                if ($to === PostStatus::Scheduled) {
                    $this->dispatchPublishJob($locked);
                }

                // `@`-mentions ("tags") in the transition note (or an Owner's approval-override
                // reason, which lands on the same note) notify each tagged teammate directly;
                // they're then excluded from the broad status audience below so a tagged user
                // doesn't also get the generic "post needs review" alert.
                $mentioned = $this->notifyMentioned($locked, $to, $comment, $user, $notifications);

                $this->notify($locked, $to, $user, $notifications, $mentioned->pluck('id'));

                // Campaigns enhancement C-6.3: the campaign's first post out marks it live.
                if (in_array($to, [PostStatus::Published, PostStatus::PartiallyPublished], true) && $locked->campaign_id !== null) {
                    app(MarkCampaignWentLiveAction::class)($locked);
                }
            });

            return $locked;
        });

        // Keep the caller's instance in step with the row (callers and tests hold on to it).
        $post->setRawAttributes($locked->getAttributes(), true);

        return $post;
    }

    /**
     * Notify the interested audience for a transition: org users (minus the actor) when a post
     * lands in `internal_review`, org users when a client decides (approved/changes requested),
     * client-portal users when a post lands in `waiting_client`.
     *
     * `$excludeIds` are users already notified directly because they were `@`-mentioned in the
     * transition note — they're dropped from the broad audience to avoid a duplicate alert.
     *
     * @param  Collection<int, int>  $excludeIds
     */
    private function notify(Post $post, PostStatus $to, ?User $user, PostNotificationService $notifications, Collection $excludeIds = new Collection): void
    {
        if ($to === PostStatus::InternalReview) {
            // Step B-3.3: in a business workspace this is Owners/Managers only; agency unchanged.
            $recipients = $notifications->internalReviewRecipients($post, $user)->whereNotIn('id', $excludeIds);

            $notifications->send($recipients, new PostWaitingForInternalReview($post));

            Log::info('Internal review notification sent', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'user_id' => $user?->id,
                'recipient_count' => $recipients->count(),
            ]);

            return;
        }

        if ($to === PostStatus::WaitingClient) {
            $notifications->send(
                $notifications->clientPortalRecipients($post)->whereNotIn('id', $excludeIds),
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
            $notifications->orgRecipients($post)->whereNotIn('id', $excludeIds),
            new PostApprovalDecided($post, $decision),
        );
    }

    /**
     * Resolve the `@[Name](id)` "tag" tokens in the transition note and notify each one directly
     * with a `PostNoteMentioned` so a tagged teammate is pulled in to look at the post. The `@`
     * mechanism is shared with post comments, but status dialogs are internal, so tokens are
     * validated against this org's recipients only (never the actor, never outsiders, never a
     * client-portal user) and never trusted at face value.
     *
     * Returns the users that were notified so the caller can keep them out of the broad status
     * audience. No-ops for the unauthenticated review portal (a `null` actor can't tag anyone).
     *
     * @return Collection<int, User>
     */
    private function notifyMentioned(Post $post, PostStatus $to, ?string $comment, ?User $user, PostNotificationService $notifications): Collection
    {
        if ($user === null || $comment === null) {
            return new Collection;
        }

        $mentionedIds = CommentMentionParser::extractUserIds($comment);

        if ($mentionedIds === []) {
            return new Collection;
        }

        $mentioned = $notifications->orgRecipients($post, exclude: $user)
            ->whereIn('id', $mentionedIds)
            ->values();

        foreach ($mentioned as $recipient) {
            $recipient->notify(new PostNoteMentioned($post, $to, $user));
        }

        if ($mentioned->isNotEmpty()) {
            Log::info('Post transition mentions notified', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'user_id' => $user->id,
                'to_status' => $to->value,
                'mentioned_user_ids' => $mentioned->pluck('id')->all(),
            ]);
        }

        return $mentioned;
    }

    /**
     * Enqueue `PublishPostJob` for this schedule (its `publish_dispatch_id`), delayed until the
     * earliest target's `scheduled_at_utc` if that instant is still in the future (immediate
     * dispatch otherwise, e.g. a past-due reschedule), and `SendScheduledPostToVendorJob` right
     * away so the vendor holds every channel that's due later. Also used by
     * `ReschedulePostAction` and `UpdatePostAction` after they move a `scheduled` post's times.
     */
    public function dispatchPublishJob(Post $post): void
    {
        if ($post->publish_dispatch_id === null) {
            Log::warning('Publish job not enqueued: post has no publish_dispatch_id', ['post_id' => $post->id]);

            return;
        }

        $publishAt = $post->targets()->whereNotNull('scheduled_at_utc')->min('scheduled_at_utc');

        SendScheduledPostToVendorJob::dispatch($post->id, $post->publish_dispatch_id);

        $job = PublishPostJob::dispatch($post->id, $post->publish_dispatch_id);

        if ($publishAt && now()->lessThan($publishAt)) {
            $job->delay(Carbon::parse($publishAt));
        }

        Log::info('Publish job enqueued for scheduled post', [
            'post_id' => $post->id,
            'publish_dispatch_id' => $post->publish_dispatch_id,
            'publish_at' => $publishAt,
        ]);
    }
}
