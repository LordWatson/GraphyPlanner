<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Posts\PostWorkflowResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step B-3.2 — the solo owner's "get this out" button. From a `draft` (or `idea`):
 *
 * - **Review off**: validate the publish checklist, then walk `→ approved → scheduled` (self-
 *   approval recorded as "Marked ready") and dispatch publishing exactly as the normal editor
 *   path does. Everything runs in **one** transaction, with one activity-log entry per transition,
 *   and every side effect (the publish job, notifications) deferred until that transaction commits
 *   (per I-1.4) — so a checklist failure or a concurrent edit leaves no partial state.
 * - **Review on** (a small team): the owner can't just push it live, so it's sent to internal
 *   review and the reviewers are notified instead.
 *
 * The whole status machine is still `TransitionPostStatusAction` / the per-type `PostWorkflow` —
 * this Action only composes the hops, so legality, logging and side effects stay in one place.
 */
class QuickSchedulePostAction
{
    /** The post's own times are used as-is. */
    public const WHEN_SCHEDULED = 'schedule';

    /** Every target is retimed to now so it publishes immediately. */
    public const WHEN_NOW = 'now';

    public function __construct(
        private readonly PostWorkflowResolver $workflows,
        private readonly TransitionPostStatusAction $transition,
        private readonly EvaluatePostChecklistAction $evaluateChecklist,
    ) {}

    /**
     * @param  self::WHEN_*  $when
     */
    public function __invoke(Post $post, User $user, string $when = self::WHEN_SCHEDULED): Post
    {
        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Idea], true)) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft can be scheduled this way.'),
            ]);
        }

        $workflow = $this->workflows->forPost($post);

        // A workspace that requires review takes the hand-off path: the owner sends it for review
        // rather than pushing it live themselves. `TransitionPostStatusAction` notifies the
        // reviewers (Owners/Managers, Step B-3.3) after commit.
        if ($workflow->requiresReview($post->organization)) {
            Log::info('Quick-schedule routed to review', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'user_id' => $user->id,
            ]);

            return ($this->transition)($post, PostStatus::InternalReview, $user, __('Sent for review'));
        }

        return DB::transaction(function () use ($post, $user, $when) {
            if ($when === self::WHEN_NOW) {
                $this->retimeTargetsToNow($post);
            }

            // The §6 checklist is the gate before anything moves — the same rule the editor's
            // transition request enforces, applied here before the first hop so a not-ready post
            // never half-transitions. A failure rolls the whole transaction back (incl. any retime).
            $checklist = ($this->evaluateChecklist)($post);

            if (! $checklist['passed']) {
                throw ValidationException::withMessages([
                    'checklist' => __('This post isn’t ready to go out yet — check the readiness list.'),
                ]);
            }

            // Normalise an idea to a draft first, so the rest is always draft → approved → scheduled.
            if ($post->status === PostStatus::Idea) {
                ($this->transition)($post, PostStatus::Draft, $user);
            }

            ($this->transition)($post, PostStatus::Approved, $user, __('Marked ready'));
            ($this->transition)($post, PostStatus::Scheduled, $user);

            Log::info('Post quick-scheduled', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'user_id' => $user->id,
                'when' => $when,
            ]);

            return $post;
        });
    }

    /**
     * Retime every target to now, on its own account's clock, so "Post now" publishes immediately
     * (`TransitionPostStatusAction::dispatchPublishJob` dispatches with no delay for a past-due
     * instant). The reminder stamp is cleared so a retimed target can still remind if relevant.
     */
    private function retimeTargetsToNow(Post $post): void
    {
        $post->loadMissing('targets.socialAccount');

        foreach ($post->targets as $target) {
            /** @var PostTarget $target */
            $timezone = $target->socialAccount->timezone ?? 'UTC';
            $localNow = now()->setTimezone($timezone);

            $target->update([
                'scheduled_local_date' => $localNow->toDateString(),
                'scheduled_local_time' => $localNow->format('H:i'),
                'scheduled_at_utc' => now(),
                'reminder_sent_at' => null,
            ]);
        }
    }
}
