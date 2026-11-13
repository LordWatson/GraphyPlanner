<?php

namespace App\Actions\Stories;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffRecipient;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Step S-4.4 / S-5.2 — the stories a member is on the hook to post by hand.
 *
 * Two kinds of row, oldest first:
 *
 *  - **ready**: a handoff is open and due — post it now;
 *  - **upcoming**: an assisted Story due inside the next day that has not been handed off yet (its
 *    post is still `scheduled`, or is publishing a different channel first).
 *
 * `mine` is the poster's own queue (`/stories/to-post`, My work): Stories for the team where they
 * are the poster, or where nobody is and so everybody is. `oversee` is what an Owner or Strategist
 * sees in Needs attention: every ready Story in the organization, including the ones a client is
 * meant to post, so somebody can chase.
 *
 * Runs with the usual access scopes on: a member restricted to some clients only sees theirs.
 */
class GetStoryQueueAction
{
    public const UPCOMING_HOURS = 24;

    public const LIMIT = 50;

    public function __construct(private readonly ResolveStoryPosterAction $resolvePoster = new ResolveStoryPosterAction) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(User $user, bool $oversee = false, bool $includeUpcoming = true): array
    {
        if (! in_array($user->role, ResolveStoryPosterAction::POSTER_ROLES, true)) {
            return [];
        }

        $ready = $this->openHandoffs($user, $oversee)
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (StoryHandoff $handoff) => $handoff->isCurrent())
            ->map(fn (StoryHandoff $handoff) => $this->handoffRow($handoff))
            ->values()
            ->all();

        if (! $includeUpcoming) {
            return array_values(array_filter($ready, fn (array $row) => $row['state'] === 'ready'));
        }

        $handedOff = array_column($ready, 'target_id');

        $upcoming = PostTarget::query()
            ->where('handoff_required', true)
            ->where('status', PostTargetStatus::Pending)
            ->whereNotIn('id', $handedOff)
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '<=', Carbon::now()->addHours(self::UPCOMING_HOURS))
            ->whereHas('post', fn (Builder $query) => $query
                ->where('org_id', $user->org_id)
                ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing])
                ->forActiveClients())
            ->with(['post.client', 'socialAccount'])
            ->orderBy('scheduled_at_utc')
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (PostTarget $target) => $this->isPosterFor($user, $target, $oversee))
            ->map(fn (PostTarget $target) => $this->upcomingRow($target))
            ->values()
            ->all();

        $rows = [...$ready, ...$upcoming];
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['due_at'], (string) $b['due_at']));

        return $rows;
    }

    /**
     * How many stories are ready for this member right now (the Home figure and the nav badge).
     */
    public function readyCount(User $user, bool $oversee = false): int
    {
        return count($this($user, $oversee, includeUpcoming: false));
    }

    /**
     * The next ready story for this member after the one they have just finished, or null.
     *
     * @return array<string, mixed>|null
     */
    public function nextFor(User $user, StoryHandoff $current): ?array
    {
        foreach ($this($user, includeUpcoming: false) as $row) {
            if ($row['handoff_id'] !== $current->id) {
                return ['url' => $row['url'], 'handle' => $row['handle'], 'client_name' => $row['client_name']];
            }
        }

        return null;
    }

    /**
     * @return Builder<StoryHandoff>
     */
    private function openHandoffs(User $user, bool $oversee): Builder
    {
        return StoryHandoff::query()
            ->open()
            ->where('org_id', $user->org_id)
            ->when(! $oversee || ! in_array($user->role, [Role::Owner, Role::Strategist], true), fn (Builder $query) => $query
                ->where('recipient_type', StoryHandoffRecipient::Team)
                ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', $user->id)))
            ->with(['post.client', 'target.socialAccount', 'user:id,name'])
            ->orderBy('due_at');
    }

    private function isPosterFor(User $user, PostTarget $target, bool $oversee): bool
    {
        $post = $target->post;

        if ($post === null) {
            return false;
        }

        if ($oversee && in_array($user->role, [Role::Owner, Role::Strategist], true)) {
            return true;
        }

        if ($target->handoffRecipient() === StoryHandoffRecipient::Client) {
            return false;
        }

        $poster = $this->resolvePoster->teamPoster($post);

        return $poster === null || $poster->id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function handoffRow(StoryHandoff $handoff): array
    {
        $target = $handoff->target;
        $account = $target?->socialAccount;
        // "Due" includes the due minute itself: the sweep notifies at `due_at <= now`.
        $isDue = $handoff->due_at->lessThanOrEqualTo(now());

        return [
            'handoff_id' => $handoff->id,
            'post_id' => $handoff->post_id,
            'target_id' => $handoff->post_target_id,
            'state' => $isDue ? 'ready' : 'upcoming',
            'client_name' => $handoff->post?->client?->name,
            'platform' => $account?->platform->value,
            'handle' => $account?->handle,
            'due_at' => $handoff->due_at->toIso8601String(),
            'due' => $this->due($handoff->due_at, $account?->timezone),
            'expires_at' => $handoff->expires_at->toIso8601String(),
            'posted_by' => $handoff->recipient_type->value,
            'poster_name' => $handoff->user?->name,
            'extras' => $target?->storyExtras()->summary(),
            'url' => route('posts.story-handoff.open', ['post' => $handoff->post_id, 'target' => $handoff->post_target_id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upcomingRow(PostTarget $target): array
    {
        $account = $target->socialAccount;
        /** @var Post $post */
        $post = $target->post;
        /** @var CarbonInterface $dueAt */
        $dueAt = $target->scheduled_at_utc;

        return [
            'handoff_id' => null,
            'post_id' => $target->post_id,
            'target_id' => $target->id,
            'state' => 'upcoming',
            'client_name' => $post->client?->name,
            'platform' => $account?->platform->value,
            'handle' => $account?->handle,
            'due_at' => $dueAt->toIso8601String(),
            'due' => $this->due($dueAt, $account?->timezone),
            'expires_at' => null,
            'posted_by' => $target->handoffRecipient()->value,
            'poster_name' => null,
            'extras' => $target->storyExtras()->summary(),
            'url' => route('posts.story-handoff.open', ['post' => $target->post_id, 'target' => $target->id]),
        ];
    }

    /**
     * The due time in the channel's own timezone, the way the schedule was set.
     *
     * @return array{date: string, time: string, timezone: string}
     */
    private function due(CarbonInterface $dueAt, ?string $timezone): array
    {
        $local = $dueAt->copy()->setTimezone($timezone ?: (string) config('app.timezone'));

        return ['date' => $local->toDateString(), 'time' => $local->format('H:i'), 'timezone' => $local->getTimezone()->getName()];
    }
}
