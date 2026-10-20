<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
     * @return list<int> the recalled target ids
     *
     * @throws ValidationException
     */
    public function recall(Post $post, string $field = 'status'): array
    {
        $post->loadMissing('targets.socialAccount.organization');

        /** @var Collection<int, PostTarget> $held */
        $held = $post->targets->filter(fn (PostTarget $target) => $target->isVendorHeld())->values();

        if ($held->isEmpty()) {
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

        return $recalled;
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
