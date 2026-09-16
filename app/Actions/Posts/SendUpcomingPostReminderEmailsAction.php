<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Mail\UpcomingPostReminderMail;
use App\Models\Organization;
use App\Models\PostTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendUpcomingPostReminderEmailsAction
{
    /**
     * Find every `Scheduled` post's `PostTarget` due to publish within the next `$days` days that
     * hasn't already been reminded about (`reminder_sent_at` still null), group them by
     * organization, and email every user of that organization a single digest via
     * `UpcomingPostReminderMail`. Marks each notified target's `reminder_sent_at` so a re-run of
     * the command never sends a duplicate reminder for the same target.
     *
     * @return int the number of `PostTarget`s that were reminded about
     */
    public function __invoke(int $days = 3): int
    {
        $now = Carbon::now();
        $until = $now->clone()->addDays($days);

        $targets = PostTarget::query()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('scheduled_at_utc')
            ->whereBetween('scheduled_at_utc', [$now, $until])
            ->whereHas('post', fn ($q) => $q->where('status', PostStatus::Scheduled))
            ->with(['post.client:id,name', 'post.organization', 'socialAccount'])
            ->get();

        if ($targets->isEmpty()) {
            return 0;
        }

        $targetsByOrg = $targets->groupBy(fn (PostTarget $target) => $target->post->org_id);

        foreach ($targetsByOrg as $orgId => $orgTargets) {
            /** @var Organization|null $organization */
            $organization = $orgTargets->first()->post->organization;

            if (! $organization) {
                continue;
            }

            $occurrences = $orgTargets
                ->map(fn (PostTarget $target) => [
                    'post_id' => $target->post->id,
                    'client_name' => $target->post->client?->name,
                    'master_caption' => $target->post->master_caption,
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_at_utc' => $target->scheduled_at_utc,
                ])
                ->sortBy('scheduled_at_utc')
                ->values()
                ->all();

            $users = $organization->users()->get();

            DB::transaction(function () use ($organization, $occurrences, $users, $orgTargets, $days) {
                foreach ($users as $user) {
                    Mail::to($user)->send(new UpcomingPostReminderMail($organization, $occurrences, $days));
                }

                PostTarget::query()
                    ->whereIn('id', $orgTargets->pluck('id'))
                    ->update(['reminder_sent_at' => Carbon::now()]);
            });

            Log::info('Upcoming post reminder emails sent', [
                'org_id' => $orgId,
                'target_count' => $orgTargets->count(),
                'user_count' => $users->count(),
            ]);
        }

        return $targets->count();
    }
}
