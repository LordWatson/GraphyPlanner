<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mail\UpcomingPostReminderMail;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Notifications\UpcomingPublishReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendUpcomingPostReminderEmailsAction
{
    /** Longer than any local day, so one organization's day is only ever announced once. */
    private const HEADS_UP_GUARD_HOURS = 36;

    /**
     * Find every `Scheduled` post's `PostTarget` due to publish within the next `$days` days that
     * hasn't already been reminded about (`reminder_sent_at` still null), group them by
     * organization, and email every user of that organization a single digest via
     * `UpcomingPostReminderMail`. Marks each notified target's `reminder_sent_at` so a re-run of
     * the command never sends a duplicate reminder for the same target.
     *
     * Step S-3.4: the morning heads-up. The assisted Stories (`handoff_required`) due today, in
     * the organization's own day, get a line of their own in the email ("2 stories to post by hand
     * today (10:00, 16:30).") and a mention in the WhatsApp summary. They are read straight from
     * the schedule, not from the targets being reminded: a Story scheduled three days ahead was
     * reminded about (and stamped) on an earlier morning, and still has to be announced on its own
     * day. So the heads-up has its own guard, once per organization per local day (a cache key, as
     * `SendNothingScheduledTomorrowPushAction` does), and an organization with Stories today but
     * nothing new to remind about is still written to, about the Stories alone.
     *
     * Step I-1.8: works one organization at a time (only the org ids are loaded up front), so a
     * busy week never pulls every due target of every organization into memory at once.
     *
     * @return int the number of `PostTarget`s that were reminded about
     */
    public function __invoke(int $days = 3): int
    {
        $now = Carbon::now();
        $until = $now->clone()->addDays($days);

        $due = fn () => $this->sendable()
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at_utc', [$now, $until]);

        $orgIds = $this->orgIds($due())
            // Anywhere on Earth, "today" lies inside a day either side of now. The exact local
            // day is worked out per organization below.
            ->merge($this->headsUpEnabled()
                ? $this->orgIds($this->assistedStories()->whereBetween('scheduled_at_utc', [$now->clone()->subDay(), $now->clone()->addDays(2)]))
                : [])
            ->unique()
            ->sort()
            ->values();

        $reminded = 0;

        foreach ($orgIds as $orgId) {
            /** @var Organization|null $organization */
            $organization = Organization::query()->find($orgId);

            if (! $organization) {
                continue;
            }

            $orgTargets = $due()
                ->whereHas('post', fn ($q) => $q->where('org_id', $orgId))
                ->with(['post.client:id,name', 'socialAccount'])
                ->get();

            $stories = $this->unannouncedStoriesToday($organization, $now);

            if ($orgTargets->isEmpty() && $stories->isEmpty()) {
                continue;
            }

            $reminded += $orgTargets->count();

            // With nothing new to remind about, the email is about today's Stories alone.
            $storiesOnly = $orgTargets->isEmpty();
            $storyIds = $stories->modelKeys();

            $occurrences = ($storiesOnly ? $stories : $orgTargets)
                ->map(fn (PostTarget $target) => [
                    'post_id' => $target->post->id,
                    'client_name' => $target->post->client?->name,
                    'master_caption' => $target->post->master_caption,
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_at_utc' => $target->scheduled_at_utc,
                    'by_hand' => $this->headsUpEnabled() && $target->handoff_required,
                ])
                ->sortBy('scheduled_at_utc')
                ->values()
                ->all();

            $storyTimes = $this->localTimes($stories, $organization);
            $soonest = $orgTargets->concat($stories)->sortBy('scheduled_at_utc')->first();

            $users = $organization->users()->get();

            // Step W-2.2: the same digest, for whoever asked for it on their phone. One message
            // per member, not one per post, and only for members who opted into `publishing` and
            // verified a number — `WhatsAppRouting` decides, in `via()`.
            $reminder = new UpcomingPublishReminder(
                // Today's Stories are counted once, whether or not they are also newly reminded.
                $orgTargets->whereNotIn('id', $storyIds)->count() + $stories->count(),
                $soonest?->post->master_caption,
                $soonest?->scheduled_at_utc,
                $stories->count(),
            );

            DB::transaction(function () use ($organization, $occurrences, $users, $orgTargets, $days, $reminder, $storyTimes, $storiesOnly) {
                foreach ($users as $user) {
                    Mail::to($user)->send(new UpcomingPostReminderMail($organization, $occurrences, $days, $storyTimes, $storiesOnly));
                    $user->notify($reminder);
                }

                PostTarget::query()
                    ->whereIn('id', $orgTargets->pluck('id'))
                    ->update(['reminder_sent_at' => Carbon::now()]);
            });

            Log::info('Upcoming post reminder emails sent', [
                'org_id' => $orgId,
                'target_count' => $orgTargets->count(),
                'user_count' => $users->count(),
                'stories_by_hand_today' => $stories->count(),
            ]);
        }

        return $reminded;
    }

    /**
     * Channels somebody should hear about: on a scheduled post, for a client that is not archived
     * (Step I-1.5), in an organization that is not suspended (Step 15.3).
     *
     * @return Builder<PostTarget>
     */
    private function sendable(): Builder
    {
        return PostTarget::query()
            ->whereNotNull('scheduled_at_utc')
            ->whereIn('post_id', Post::query()->where('status', PostStatus::Scheduled)->forActiveClients()->select('id'))
            ->whereHas('post.organization', fn ($q) => $q->whereNull('suspended_at'));
    }

    /**
     * Assisted Stories still waiting to go out: not sent to the vendor, posted from a phone.
     *
     * @return Builder<PostTarget>
     */
    private function assistedStories(): Builder
    {
        return $this->sendable()
            ->where('handoff_required', true)
            ->where('post_targets.status', PostTargetStatus::Pending);
    }

    /**
     * Only while the lane is switched on, so the email never promises a reminder that will not come.
     */
    private function headsUpEnabled(): bool
    {
        return (bool) config('publishing.story_handoff.enabled');
    }

    /**
     * @param  Builder<PostTarget>  $targets
     * @return SupportCollection<int, int>
     */
    private function orgIds(Builder $targets): SupportCollection
    {
        return $targets
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->distinct()
            ->pluck('posts.org_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * The organization's assisted Stories due in its own today, unless today was already
     * announced. Claims the day when there is something to say, so a re-run says nothing twice.
     *
     * @return Collection<int, PostTarget>
     */
    private function unannouncedStoriesToday(Organization $organization, Carbon $now): Collection
    {
        $none = new Collection;

        if (! $this->headsUpEnabled()) {
            return $none;
        }

        $local = $now->clone()->setTimezone($organization->default_timezone);

        $stories = $this->assistedStories()
            ->whereHas('post', fn ($q) => $q->where('org_id', $organization->id))
            ->whereBetween('scheduled_at_utc', [$local->clone()->startOfDay()->utc(), $local->clone()->endOfDay()->utc()])
            ->with(['post.client:id,name', 'socialAccount'])
            ->orderBy('scheduled_at_utc')
            ->orderBy('id')
            ->get();

        if ($stories->isEmpty()) {
            return $none;
        }

        // Idempotent per organization per local day.
        $guard = "story-heads-up:{$organization->id}:{$local->toDateString()}";

        return Cache::add($guard, true, $now->clone()->addHours(self::HEADS_UP_GUARD_HOURS)) ? $stories : $none;
    }

    /**
     * Each Story's time in its own channel's timezone, in the order given.
     *
     * @param  Collection<int, PostTarget>  $stories
     * @return list<string> e.g. `['10:00', '16:30']`
     */
    private function localTimes(Collection $stories, Organization $organization): array
    {
        $times = [];

        foreach ($stories as $story) {
            if ($story->scheduled_at_utc !== null) {
                $times[] = $story->scheduled_at_utc->clone()
                    ->setTimezone($story->socialAccount?->timezone ?: $organization->default_timezone)
                    ->format('H:i');
            }
        }

        return $times;
    }
}
