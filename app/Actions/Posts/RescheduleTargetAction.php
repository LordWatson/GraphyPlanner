<?php

namespace App\Actions\Posts;

use App\Models\PostTarget;
use App\Models\User;
use App\Services\Publishing\VendorScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RescheduleTargetAction
{
    /**
     * Move a single target of a post to a new local date — and, on the week/day time grid, a new
     * local time — leaving every sibling target untouched (the calendar's single-channel drag,
     * Step M-5.7 / I-5.1).
     *
     * Only this target's `scheduled_local_date`/`scheduled_local_time` change; `scheduled_at_utc`
     * is re-derived from the account's own timezone, so independently-scheduled local times are
     * never collapsed together (spec §4.6). `reminder_sent_at` is cleared so the upcoming-post
     * reminder fires again for the new slot. A move into the past is rejected as a validation error
     * on `scheduled_local_date`.
     *
     * Vendor lifecycle mirrors `ReschedulePostAction`: a vendor-held post is recalled first
     * (nothing moves if that's refused) then re-submitted at the new times, and a `scheduled` post
     * is re-dispatched with a fresh dispatch id so the stale queued job no-ops.
     */
    public function __invoke(
        PostTarget $target,
        string $localDate,
        ?string $localTime = null,
        ?User $user = null,
        TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ): PostTarget {
        $target->loadMissing('socialAccount', 'post.targets.socialAccount');
        $post = $target->post;

        // A month-grid drop only carries a day, so the target keeps its own time; the week/day
        // time grid passes the dropped slot's time as well.
        $time = $localTime ?? $target->scheduled_local_time;
        $timezone = $target->socialAccount->timezone;

        $scheduledAtUtc = filled($time)
            ? Carbon::parse($localDate.' '.$time, $timezone)->utc()
            : null;

        $isPast = $scheduledAtUtc !== null
            ? $scheduledAtUtc->isPast()
            : $localDate < Carbon::now($timezone)->toDateString();

        if ($isPast) {
            throw ValidationException::withMessages([
                'scheduled_local_date' => __('Posts can’t be moved into the past.'),
            ]);
        }

        // Step I-3.6 / M-2.5: a post the vendor is holding for later is recalled first (nothing
        // moves if that's refused), then re-submitted at the new times once saved.
        $vendor = app(VendorScheduleService::class);
        $recalled = $post->canBeRecalled() || $post->isHeldByVendor()
            ? $vendor->recall($post, 'scheduled_local_date')
            : [];

        DB::transaction(function () use ($target, $post, $localDate, $time, $scheduledAtUtc, $transition, $vendor, $recalled) {
            $target->update([
                'scheduled_local_date' => $localDate,
                'scheduled_local_time' => $time,
                'scheduled_at_utc' => $scheduledAtUtc,
                'reminder_sent_at' => null,
            ]);

            ReschedulePostAction::redispatchIfScheduled($post, $transition);

            DB::afterCommit(fn () => $vendor->resubmit($post, $recalled));
        });

        Log::info('Post target rescheduled from calendar', [
            'post_id' => $post->id,
            'target_id' => $target->id,
            'org_id' => $post->org_id,
            'user_id' => $user?->id,
            'has_time' => $scheduledAtUtc !== null,
        ]);

        return $target->refresh();
    }
}
