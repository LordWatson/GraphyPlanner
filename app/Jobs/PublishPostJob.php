<?php

namespace App\Jobs;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Publishing\ResolvePostPublishOutcomeAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step 1.4 — the queued job dispatched once a post is transitioned to `scheduled` (see
 * `TransitionPostStatusAction`). Nothing calls the vendor before this job runs, and this job
 * only runs once the §6 checklist has already passed (enforced server-side by
 * `TransitionPostRequest` before the `scheduled` transition is even allowed).
 *
 * Step I-1.2: the job carries the post's `publish_dispatch_id` from when it was dispatched. If
 * the post has since left `scheduled` (or been rescheduled, which mints a new id), the ids no
 * longer match and the job no-ops — a stale delayed job can never publish early.
 *
 * Step I-1.3: the post row is locked while it moves `scheduled → publishing`, so two workers
 * can't both pass the status check. Every unsent target then gets a fresh `publish_attempt_id`
 * and its own `PublishPostTargetJob` in one `Bus::batch()`; the post is finalized by
 * `ResolvePostPublishOutcomeAction` as the targets report back. Retries/backoff/timeout are set
 * here, the job is unique per post + dispatch id, and `failed()` makes sure a crash never
 * leaves the post stuck in `publishing`.
 */
class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 60;

    /**
     * Declared (not promoted) with a default, so jobs serialized before Step I-1.2 — which had
     * no dispatch id — still unserialize. They match posts whose id is also null.
     */
    public ?string $dispatchId = null;

    public function __construct(public readonly int $postId, ?string $dispatchId = null)
    {
        $this->dispatchId = $dispatchId;
        // Step I-2.8: publishing has its own queue and workers.
        $this->onQueue(Queues::PUBLISHING);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * Unique per schedule, not per post: a reschedule dispatches a new job (new dispatch id)
     * while the stale one may still be queued holding its own lock.
     */
    public function uniqueId(): string
    {
        return $this->postId.':'.($this->dispatchId ?? 'none');
    }

    public function handle(TransitionPostStatusAction $transition, ResolvePostPublishOutcomeAction $resolve): void
    {
        $claimed = DB::transaction(fn (): ?Post => $this->claim($transition));

        if ($claimed === null) {
            return;
        }

        /** @var Collection<int, PostTarget> $targets */
        $targets = $claimed->targets()
            ->where('status', PostTargetStatus::Pending)
            ->whereNull('external_post_id')
            ->whereNull('publish_requested_at')
            ->get();

        Log::info('PublishPostJob: publishing post', [
            'post_id' => $claimed->id,
            'org_id' => $claimed->org_id,
            'target_count' => $targets->count(),
        ]);

        if ($targets->isEmpty()) {
            // Nothing to send (no targets) — resolve straight away so the post doesn't hang.
            $resolve($claimed);

            return;
        }

        self::dispatchTargets($claimed, $targets);
    }

    /**
     * Fan out one `PublishPostTargetJob` per target in a single batch (also used by
     * `RetryFailedPostTargetsAction`). Targets must already carry their `publish_attempt_id`.
     *
     * @param  Collection<int, PostTarget>  $targets
     */
    public static function dispatchTargets(Post $post, Collection $targets): void
    {
        Bus::batch(
            $targets->map(fn (PostTarget $target) => new PublishPostTargetJob($target->id, (string) $target->publish_attempt_id))->all(),
        )
            ->name("publish-post-{$post->id}")
            ->allowFailures()
            ->dispatch();
    }

    /**
     * Crashed past every retry. Whatever state the post was left in (`scheduled` with this
     * dispatch id, or `publishing` with targets never sent), fail the unsent targets and resolve
     * the post — it ends up `failed` (with the error on the activity log, and the org notified)
     * instead of stuck.
     */
    public function failed(?Throwable $exception): void
    {
        $error = 'Publishing crashed before it reached the platform: '.($exception?->getMessage() ?? 'unknown error');

        Log::error('PublishPostJob failed', [
            'post_id' => $this->postId,
            'publish_dispatch_id' => $this->dispatchId,
            'error' => $exception?->getMessage(),
        ]);

        $post = DB::transaction(function () use ($error): ?Post {
            /** @var Post|null $post */
            $post = Post::query()->whereKey($this->postId)->lockForUpdate()->first();

            if (! $post) {
                return null;
            }

            if ($post->status === PostStatus::Scheduled && $post->publish_dispatch_id === $this->dispatchId) {
                $post = app(TransitionPostStatusAction::class)($post, PostStatus::Publishing, null, $error);
            }

            if ($post->status !== PostStatus::Publishing) {
                return null;
            }

            $post->targets()
                ->where('status', PostTargetStatus::Pending)
                ->whereNull('external_post_id')
                ->whereNull('publish_requested_at')
                ->update(['status' => PostTargetStatus::Failed, 'error' => $error]);

            return $post;
        });

        if ($post) {
            app(ResolvePostPublishOutcomeAction::class)($post);
        }
    }

    /**
     * Why this post's publishing is on hold, or null when it may go out. The post stays
     * `scheduled` either way, so nothing is lost when the hold lifts. Shared with
     * `SendScheduledPostToVendorJob`.
     */
    public static function pausedReason(Post $post): ?string
    {
        // Step I-1.5: an archived client (or a deleted organization) pauses publishing.
        if ($post->organization === null || $post->client?->trashed()) {
            return 'client archived or organization deleted';
        }

        // Step 15.3: a suspended organization's content never goes out.
        if ($post->organization->isSuspended()) {
            return 'organization is suspended';
        }

        return null;
    }

    /**
     * Inside a transaction: lock the post, make sure this job still owns its schedule, move it to
     * `publishing` and give every not-yet-published target a fresh attempt id.
     */
    private function claim(TransitionPostStatusAction $transition): ?Post
    {
        /** @var Post|null $post */
        $post = Post::query()->whereKey($this->postId)->lockForUpdate()->first();

        if (! $post) {
            Log::warning('PublishPostJob: post not found', ['post_id' => $this->postId]);

            return null;
        }

        if ($post->publish_dispatch_id !== $this->dispatchId) {
            Log::info('PublishPostJob: stale dispatch, skipping', [
                'post_id' => $post->id,
                'job_dispatch_id' => $this->dispatchId,
                'post_dispatch_id' => $post->publish_dispatch_id,
            ]);

            return null;
        }

        // The post may have moved on (e.g. manually reverted to draft) since this job was
        // enqueued — never publish a post that isn't still in the `scheduled` state.
        if ($post->status !== PostStatus::Scheduled) {
            Log::info('PublishPostJob: post is no longer scheduled, skipping', [
                'post_id' => $post->id,
                'status' => $post->status->value,
            ]);

            return null;
        }

        if ($reason = self::pausedReason($post)) {
            Log::warning('PublishPostJob: '.$reason.', skipping', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'client_id' => $post->client_id,
            ]);

            return null;
        }

        $post = $transition($post, PostStatus::Publishing, null);

        // Channels `SendScheduledPostToVendorJob` already handed over (accepted, or a request in
        // flight) stay as they are — resetting them would send the post twice.
        $unsent = $post->targets()
            ->where('status', '!=', PostTargetStatus::Published)
            ->where(fn ($query) => $query
                ->where('status', '!=', PostTargetStatus::Pending)
                ->orWhere(fn ($query) => $query->whereNull('external_post_id')->whereNull('publish_requested_at')))
            ->get();

        foreach ($unsent as $target) {
            $target->update([
                'status' => PostTargetStatus::Pending,
                'external_post_id' => null,
                'error' => null,
                'publish_attempt_id' => (string) Str::uuid(),
                'publish_requested_at' => null,
            ]);
        }

        return $post;
    }
}
