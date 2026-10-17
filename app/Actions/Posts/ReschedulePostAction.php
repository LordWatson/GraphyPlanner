<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     */
    public function __invoke(Post $post, int $days, ?User $user = null): Post
    {
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

        DB::transaction(function () use ($post, $changes) {
            foreach ($post->targets as $target) {
                if (isset($changes[$target->id])) {
                    $target->update($changes[$target->id]);
                }
            }
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
}
