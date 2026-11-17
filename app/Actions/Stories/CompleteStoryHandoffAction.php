<?php

namespace App\Actions\Stories;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\StoryHandoffOutcome;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step S-2.4 — "Posted": the person confirms an assisted Story went out.
 *
 * Publishes the channel through the pipeline's own outcome seam (`SyncPublishStatusAction`), so
 * the post resolves exactly as it does when a vendor confirms a delivery: a Story-only post
 * becomes `published`; a mixed post waits for its other channels. The activity log records who
 * confirmed it and that it was posted by hand.
 *
 * "Posted" is the poster's word (`story-extras.md` §1.4): Alima cannot see Instagram, so there is
 * no `post_url` or `platform_post_id` for an assisted Story.
 *
 * **Late confirmation.** Somebody can post on time and tap "Posted" after the window, by which
 * point the channel has already failed as missed. That is still accepted — from the link while it
 * opens, and from the editor's "Mark as posted" — as long as the channel is still failed for that
 * reason on the same attempt. The post is moved the way "Retry failed channels" moves it
 * (`failed`/`partially_published` → `publishing`) and then resolves again with the Story counted
 * as published. Anything else — a second confirm, a stale attempt, a recalled post — is refused
 * with a plain message.
 */
class CompleteStoryHandoffAction
{
    public function __construct(
        private readonly SyncPublishStatusAction $sync = new SyncPublishStatusAction,
        private readonly TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ) {}

    /**
     * @param  User|null  $by  null when confirmed from the handoff link by somebody not signed in
     * @param  string  $via  `StoryHandoff::VIA_*`
     *
     * @throws ValidationException when the handoff can no longer be confirmed
     */
    public function __invoke(StoryHandoff $handoff, ?User $by = null, string $via = StoryHandoff::VIA_LINK): StoryHandoff
    {
        return DB::transaction(function () use ($handoff, $by, $via): StoryHandoff {
            $locked = StoryHandoff::lockForChange($handoff->id);
            $target = $locked?->target;
            $post = $locked?->post;

            if ($locked === null || $target === null || $post === null) {
                throw $this->inactive();
            }

            $late = ! $locked->isOpen();

            if ($late ? ! $this->canConfirmLate($locked, $target, $post) : ! $locked->isCurrent()) {
                throw $this->inactive();
            }

            if ($late) {
                // The same edge "Retry failed channels" uses; the post resolves again below.
                ($this->transition)($post, PostStatus::Publishing, $by, __('A story marked as not posted was confirmed as posted after all.'));
            }

            $locked->update([
                'outcome' => StoryHandoffOutcome::Posted,
                'completed_at' => now(),
                'completed_by_user_id' => $by?->id,
                'completed_via' => $via,
            ]);

            $target->loadMissing('socialAccount');
            $account = $target->socialAccount;
            $channel = $account ? "{$account->platform->label()} ({$account->handle})" : __('A channel');

            $post->activityLogs()->create([
                'user_id' => $by?->id,
                'from_status' => PostStatus::Publishing,
                'to_status' => PostStatus::Publishing,
                'note' => match ($via) {
                    StoryHandoff::VIA_LINK => $by !== null
                        ? __(':channel: story posted by hand.', ['channel' => $channel])
                        : __(':channel: story posted by hand, confirmed from the handoff link.', ['channel' => $channel]),
                    default => __(':channel: story marked as posted by hand.', ['channel' => $channel]),
                },
            ]);

            Log::info('Story handoff confirmed as posted', [
                'org_id' => $locked->org_id,
                'post_id' => $locked->post_id,
                'post_target_id' => $locked->post_target_id,
                'story_handoff_id' => $locked->id,
                'user_id' => $by?->id,
                'via' => $via,
                'late' => $late,
            ]);

            // Sets `published_at` and resolves the post (`ResolvePostPublishOutcomeAction`).
            ($this->sync)($target, true);

            return $locked;
        });
    }

    /**
     * A handoff that expired as missed can still be confirmed while nothing else has happened to
     * its channel: same attempt, still failed, and failed for that reason.
     */
    public function canConfirmLate(StoryHandoff $handoff, PostTarget $target, Post $post): bool
    {
        return $handoff->outcome === StoryHandoffOutcome::Missed
            && $target->publish_attempt_id === $handoff->publish_attempt_id
            && $target->status === PostTargetStatus::Failed
            && PublishFailureReason::classify($target->error) === PublishFailureReason::StoryNotPosted
            && in_array($post->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true);
    }

    private function inactive(): ValidationException
    {
        return ValidationException::withMessages([
            'handoff' => __('This link is no longer active. Open the post in Alima to see where it’s up to.'),
        ]);
    }
}
