<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Services\Publishing\VendorScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReschedulePostAction
{
    /**
     * Shift every dated target of a post by a whole number of days (the calendar's drag-and-drop
     * move). Each target keeps its own local wall-clock time and only its own
     * `scheduled_local_date` moves, so independently-scheduled local times are never collapsed
     * together (spec §4.6); `scheduled_at_utc` is re-derived per target from its account timezone.
     *
     * A target's `reminder_sent_at` is cleared so the upcoming-post reminder fires again for the
     * new date. Rejects (as a validation error on `days`) any move that would land a target in
     * the past.
     *
     * Step I-1.2: a `scheduled` post can be moved too. Its `publish_dispatch_id` is replaced in
     * the same transaction and a new `PublishPostJob` is dispatched after commit, so the job still
     * queued for the old time no-ops when it fires and the post publishes at the new time
     * (earlier or later).
     *
     * Step I-3.6 / M-2.5: a `publishing` post whose channels are all still held by the vendor
     * (`Post::canBeRecalled()`) can be moved too: the held publishes are cancelled first, then
     * re-submitted at the new times.
     */
    public function __invoke(
        Post $post,
        int $days,
        ?User $user = null,
        TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ): Post {
        $post->loadMissing('targets.socialAccount');

        $changes = [];

        foreach ($post->targets as $target) {
            if ($target->scheduled_local_date === null) {
                continue;
            }

            $timezone = $target->socialAccount->timezone;
            $date = $target->scheduled_local_date->copy()->addDays($days)->toDateString();

            $scheduledAtUtc = filled($target->scheduled_local_time)
                ? Carbon::parse($date.' '.$target->scheduled_local_time, $timezone)->utc()
                : null;

            $isPast = $scheduledAtUtc !== null
                ? $scheduledAtUtc->isPast()
                : $date < Carbon::now($timezone)->toDateString();

            if ($isPast) {
                throw ValidationException::withMessages([
                    'days' => __('Posts can’t be moved into the past.'),
                ]);
            }

            $changes[$target->id] = [
                'scheduled_local_date' => $date,
                'scheduled_at_utc' => $scheduledAtUtc,
                'reminder_sent_at' => null,
            ];
        }

        // Step I-3.6 / M-2.5: a post the vendor is holding for later is recalled first (nothing
        // moves if that's refused), then re-submitted at the new times once saved.
        $vendor = app(VendorScheduleService::class);
        $recalled = $post->canBeRecalled() || $post->isHeldByVendor() ? $vendor->recall($post, 'days') : [];

        DB::transaction(function () use ($post, $changes, $transition, $vendor, $recalled) {
            foreach ($post->targets as $target) {
                if (isset($changes[$target->id])) {
                    $target->update($changes[$target->id]);
                }
            }

            $this->redispatchIfScheduled($post, $transition);

            DB::afterCommit(fn () => $vendor->resubmit($post, $recalled));
        });

        Log::info('Post rescheduled from calendar', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'user_id' => $user?->id,
            'days' => $days,
            'target_count' => count($changes),
        ]);

        return $post->refresh();
    }

    /**
     * Give a `scheduled` post a fresh dispatch id and queue its publish job for the new times
     * (after commit). Shared with `UpdatePostAction`. Must run inside a transaction.
     */
    public static function redispatchIfScheduled(Post $post, TransitionPostStatusAction $transition): void
    {
        /** @var Post|null $locked */
        $locked = Post::query()->whereKey($post->id)->lockForUpdate()->first();

        if ($locked?->status !== PostStatus::Scheduled) {
            return;
        }

        $locked->update(['publish_dispatch_id' => (string) Str::uuid()]);

        DB::afterCommit(fn () => $transition->dispatchPublishJob($locked));

        Log::info('Scheduled post re-dispatched after a schedule change', [
            'post_id' => $locked->id,
            'org_id' => $locked->org_id,
            'publish_dispatch_id' => $locked->publish_dispatch_id,
        ]);
    }
}
