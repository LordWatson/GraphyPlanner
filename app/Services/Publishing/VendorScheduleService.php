<?php

namespace App\Services\Publishing;

use App\Actions\Stories\CancelStoryHandoffsAction;
use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step I-3.6 / M-2.5 — keeps vendor-held publishes in step with the post, so unscheduling,
 * editing, moving or pausing a post never double-posts.
 *
 * A post with channels at different local times is sent to the vendor when its earliest channel
 * is due; the later ones go with a future `scheduled_date` and the vendor holds them
 * (`PostTarget::isVendorHeld()`). Before such a post changes, `recall()` cancels every held
 * channel *first*: if the vendor says it's too late, nothing changes and the caller gets a
 * validation error naming the channel, so the old version never goes out next to a new one. After
 * the change is saved, `resubmit()` sends the recalled channels again with their new content and
 * times (one `PublishPostTargetJob` each, the Step I-1.3 path).
 *
 * The vendor calls run outside any database transaction.
 */
class VendorScheduleService
{
    public function __construct(private readonly PublishAdapter $adapter) {}

    /**
     * Cancel every vendor-held channel of the post. On success each recalled target is back to an
     * unsent `pending` (no vendor id, no attempt) and its id is returned. Throws (and leaves the
     * ones it couldn't cancel held) when the vendor refuses any of them.
     *
     * Step S-2.5: on a `publishing` post, a Story waiting to be posted by hand is recalled too —
     * its handoff is closed as `cancelled` (the link stops working) and the channel reset the same
     * way. `$keepHandoffs` leaves those alone: an edit doesn't need to take a handoff back, since
     * the handoff page reads the post live.
     *
     * @return list<int> the recalled target ids
     *
     * @throws ValidationException
     */
    public function recall(Post $post, string $field = 'status', bool $keepHandoffs = false): array
    {
        $post->loadMissing('targets.socialAccount.organization');

        /** @var Collection<int, PostTarget> $held */
        $held = $post->targets->filter(fn (PostTarget $target) => $target->isVendorHeld())->values();

        // Step S-2.5: Stories waiting to be posted by hand. There is no vendor to ask, so these
        // can always be taken back — but only once every vendor channel has agreed (below), which
        // keeps the "refused = nothing changes" guarantee.
        /** @var Collection<int, PostTarget> $awaiting */
        $awaiting = $keepHandoffs || $post->status !== PostStatus::Publishing
            ? new Collection
            : $post->targets->filter(fn (PostTarget $target) => $target->isAwaitingHandoff())->values();

        if ($held->isEmpty() && $awaiting->isEmpty()) {
            return [];
        }

        $recalled = [];
        $refused = [];

        foreach ($held as $target) {
            $result = $this->adapter->cancel($target);

            if (! $result->cancelled) {
                $refused[] = ($target->socialAccount?->platform->label() ?? 'A channel').' ('.($target->socialAccount->handle ?? '?').'): '.$result->error;

                continue;
            }

            $target->update([
                'status' => PostTargetStatus::Pending,
                'external_post_id' => null,
                'error' => null,
                'publish_attempt_id' => null,
                'publish_requested_at' => null,
            ]);
            $recalled[] = $target->id;
        }

        Log::info('Vendor-held channels recalled', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'recalled' => count($recalled),
            'refused' => count($refused),
        ]);

        if ($refused !== []) {
            throw ValidationException::withMessages([
                $field => __('This can’t be changed now — :channels Everything else is unchanged.', [
                    'channels' => implode('; ', $refused).'.',
                ]),
            ]);
        }

        if ($awaiting->isNotEmpty()) {
            array_push($recalled, ...$this->recallAwaitingHandoffs($post, $awaiting));
        }

        return $recalled;
    }

    /**
     * Take back the Stories that are waiting to be posted by hand: one transaction, locked in the
     * order every Story action uses (post, channel, handoff), and re-checked on the locked rows —
     * a "Posted" that landed a moment ago wins, and that channel is left exactly as it is.
     *
     * @param  Collection<int, PostTarget>  $awaiting
     * @return list<int> the channels that were actually recalled
     */
    private function recallAwaitingHandoffs(Post $post, Collection $awaiting): array
    {
        return DB::transaction(function () use ($post, $awaiting): array {
            Post::query()->whereKey($post->id)->lockForUpdate()->first();

            $recalled = [];

            $locked = PostTarget::query()
                ->whereIn('id', $awaiting->map(fn (PostTarget $target) => $target->id)->all())
                ->lockForUpdate()
                ->get();

            foreach ($locked as $target) {
                if (! $target->isAwaitingHandoff()) {
                    continue;
                }

                $target->update([
                    'status' => PostTargetStatus::Pending,
                    'external_post_id' => null,
                    'error' => null,
                    'publish_attempt_id' => null,
                    'publish_requested_at' => null,
                ]);
                $recalled[] = $target->id;
            }

            app(CancelStoryHandoffsAction::class)($recalled, 'post recalled');

            return $recalled;
        });
    }

    /**
     * Best-effort `recall()` for every `scheduled` post in the query that the vendor is holding —
     * for actions that must not be blocked by the vendor (archiving a client, suspending or
     * deleting an organization). A refusal is logged as an error, since that channel will still
     * go out. Returns how many posts were released.
     *
     * @param  Builder<Post>  $posts
     */
    public function releaseScheduled(Builder $posts): int
    {
        $released = 0;

        $held = $posts->clone()
            ->where('status', PostStatus::Scheduled)
            ->whereHas('targets', fn (Builder $query) => $query
                ->where('status', PostTargetStatus::Pending)
                ->whereNotNull('external_post_id')
                ->where('scheduled_at_utc', '>', now()))
            ->get();

        foreach ($held as $post) {
            try {
                $this->recall($post);
                $released++;
            } catch (ValidationException $e) {
                Log::error('Vendor refused to release a held post; it may still go out', [
                    'post_id' => $post->id,
                    'org_id' => $post->org_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    /**
     * Send recalled channels to the vendor again (with whatever content and times they have now)
     * if the post is still `publishing`. Channels whose new time has passed publish immediately.
     *
     * @param  list<int>  $targetIds
     */
    public function resubmit(Post $post, array $targetIds): void
    {
        if ($targetIds === []) {
            return;
        }

        $post->refresh();

        if ($post->status !== PostStatus::Publishing) {
            return;
        }

        $targets = $post->targets()
            ->whereIn('id', $targetIds)
            ->where('status', PostTargetStatus::Pending)
            ->whereNull('external_post_id')
            ->get();

        foreach ($targets as $target) {
            $target->update(['publish_attempt_id' => (string) Str::uuid(), 'publish_requested_at' => null]);
        }

        if ($targets->isNotEmpty()) {
            PublishPostJob::dispatchTargets($post, $targets);
        }

        Log::info('Recalled channels re-submitted', ['post_id' => $post->id, 'org_id' => $post->org_id, 'count' => $targets->count()]);
    }
}
