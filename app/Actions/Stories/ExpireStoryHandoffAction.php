<?php

namespace App\Actions\Stories;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\StoryHandoffOutcome;
use App\Jobs\PublishPostTargetJob;
use App\Models\StoryHandoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step S-2.3 — nobody confirmed an assisted Story inside its window.
 *
 * An assisted Story never sits pending forever and never goes quiet (`story-extras.md` §1.3): past
 * its window the channel **fails**, through the pipeline's own outcome seam
 * (`SyncPublishStatusAction`), so the post resolves to `failed` or `partially_published`, lands in
 * Needs attention and can be retried — which opens a fresh handoff and sends a new reminder.
 *
 * Step S-6.2: when a person chose, on this post and in advance, "if no one posts it, publish it
 * without the link" (`story_extras.if_missed = publish_plain`), the Story goes to the vendor as a
 * plain Story instead. The stored `handoff_required` is not touched: the vendor job is told, for
 * this one attempt only, that it may send an assisted channel.
 *
 * The handoff is locked first, so a "Posted" arriving at the same moment wins and nothing is sent.
 */
class ExpireStoryHandoffAction
{
    /** What the failed channel reads. `PublishFailureReason::StoryNotPosted` is recognised from it. */
    public const MISSED_ERROR = 'Nobody confirmed this story was posted. Post it now or retry to send a new reminder.';

    public function __construct(private readonly SyncPublishStatusAction $sync = new SyncPublishStatusAction) {}

    /**
     * @return StoryHandoffOutcome|null how it was closed, or null when it was no longer open
     */
    public function __invoke(StoryHandoff $handoff): ?StoryHandoffOutcome
    {
        return DB::transaction(function () use ($handoff): ?StoryHandoffOutcome {
            $locked = StoryHandoff::lockForChange($handoff->id);

            if ($locked === null || ! $locked->isOpen() || ! $locked->isCurrent()) {
                return null;
            }

            $target = $locked->target;
            $post = $locked->post;

            if ($target === null || $post === null) {
                return null;
            }

            $context = [
                'org_id' => $locked->org_id,
                'post_id' => $locked->post_id,
                'post_target_id' => $locked->post_target_id,
                'story_handoff_id' => $locked->id,
            ];

            if ($target->storyExtras()->publishesPlainIfMissed()) {
                $locked->update(['outcome' => StoryHandoffOutcome::PublishedPlain, 'completed_at' => now()]);

                $target->loadMissing('socialAccount');
                $post->activityLogs()->create([
                    'user_id' => null,
                    'from_status' => $post->status,
                    'to_status' => $post->status,
                    'note' => __('Nobody confirmed the story for :account was posted by hand, so it is going out without its link and stickers, as this post asked.', [
                        'account' => $target->socialAccount->handle ?? __('this account'),
                    ]),
                ]);

                $attemptId = (string) $target->publish_attempt_id;

                DB::afterCommit(fn () => PublishPostTargetJob::dispatch($target->id, $attemptId, true));

                Log::warning('Story handoff missed: publishing the story without its extras', $context);

                return StoryHandoffOutcome::PublishedPlain;
            }

            $locked->update(['outcome' => StoryHandoffOutcome::Missed, 'completed_at' => now()]);

            Log::warning('Story handoff missed: nobody confirmed it was posted', $context);

            // Fails the channel and resolves the post (`failed` / `partially_published`), which is
            // also what notifies the team.
            ($this->sync)($target, false, __(self::MISSED_ERROR));

            return StoryHandoffOutcome::Missed;
        });
    }
}
