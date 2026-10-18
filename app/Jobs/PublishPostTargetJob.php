<?php

namespace App\Jobs;

use App\Actions\Publishing\ResolvePostPublishOutcomeAction;
use App\Contracts\PublishAdapter;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Publishing\TargetResult;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step I-1.3 — sends one `PostTarget` to the vendor. `PublishPostJob` (and "Retry failed
 * targets") fan out one of these per target in a `Bus::batch()`, so one platform's failure never
 * blocks the others and a retry resends only what failed.
 *
 * At-most-once per attempt: the target row is locked, the job must still own the target's
 * `publish_attempt_id`, and `publish_requested_at` is stamped right before the vendor call. A
 * re-run that finds the stamp without an `external_post_id` can't know whether the vendor got the
 * request (the worker died mid-call), so the target is failed with an explanation rather than
 * risk a double post. The attempt id also goes to the vendor as `Idempotency-Key`.
 *
 * A vendor acceptance leaves the target `pending` with its `external_post_id` until the webhook
 * or poll confirms delivery (`SyncPublishStatusAction`); a rejection fails it. Either way
 * `ResolvePostPublishOutcomeAction` then decides whether the post is finished.
 */
class PublishPostTargetJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $targetId,
        public readonly string $attemptId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(PublishAdapter $adapter, ResolvePostPublishOutcomeAction $resolve): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $target = $this->claim();

        if ($target === null) {
            return;
        }

        /** @var Post $post */
        $post = Post::with('targets.socialAccount', 'assets', 'organization', 'client')->findOrFail($target->post_id);
        $account = $post->targets->firstWhere('id', $target->id)?->socialAccount;

        $result = $account
            ? $adapter->publish($post, collect([$account]))->first()
            : null;

        $result ??= new TargetResult(
            accountId: $target->social_account_id,
            ok: false,
            error: $account ? 'The publishing service returned no result for this channel.' : 'This social account no longer exists.',
        );

        $target->update([
            'status' => $result->ok ? PostTargetStatus::Pending : PostTargetStatus::Failed,
            'external_post_id' => $result->externalPostId,
            'error' => $result->error,
        ]);

        Log::info('PublishPostTargetJob: vendor responded', [
            'post_id' => $post->id,
            'post_target_id' => $target->id,
            'social_account_id' => $target->social_account_id,
            'accepted' => $result->ok,
        ]);

        $resolve($post);
    }

    /**
     * Crashed past every retry: fail the target (if this attempt still owns it and nothing was
     * recorded yet) so the post is finalized instead of sitting in `publishing`.
     */
    public function failed(?Throwable $exception): void
    {
        $target = PostTarget::find($this->targetId);

        if (! $target || $target->publish_attempt_id !== $this->attemptId
            || $target->status !== PostTargetStatus::Pending || $target->external_post_id !== null) {
            return;
        }

        $target->update([
            'status' => PostTargetStatus::Failed,
            'error' => 'Publishing crashed before the platform confirmed it: '.($exception?->getMessage() ?? 'unknown error'),
        ]);

        Log::error('PublishPostTargetJob failed', [
            'post_id' => $target->post_id,
            'post_target_id' => $target->id,
            'error' => $exception?->getMessage(),
        ]);

        if ($post = $target->post) {
            app(ResolvePostPublishOutcomeAction::class)($post);
        }
    }

    /**
     * Lock the target and take ownership of the vendor call, or return null when this job must
     * not send (stale attempt, already sent, or an unknown outcome from a crashed earlier run).
     */
    private function claim(): ?PostTarget
    {
        $unknownOutcome = false;

        $target = DB::transaction(function () use (&$unknownOutcome): ?PostTarget {
            /** @var PostTarget|null $target */
            $target = PostTarget::query()->whereKey($this->targetId)->lockForUpdate()->first();

            if (! $target || $target->publish_attempt_id !== $this->attemptId) {
                Log::info('PublishPostTargetJob: stale attempt, skipping', [
                    'post_target_id' => $this->targetId,
                    'publish_attempt_id' => $this->attemptId,
                ]);

                return null;
            }

            if ($target->status !== PostTargetStatus::Pending || $target->external_post_id !== null) {
                Log::info('PublishPostTargetJob: target already sent, skipping', ['post_target_id' => $target->id]);

                return null;
            }

            if ($target->publish_requested_at !== null) {
                $target->update([
                    'status' => PostTargetStatus::Failed,
                    'error' => 'Publishing was interrupted mid-request, so we can’t tell whether the platform received it. Check the channel before retrying.',
                ]);
                $unknownOutcome = true;

                Log::warning('PublishPostTargetJob: earlier run died mid-request, not resending', [
                    'post_id' => $target->post_id,
                    'post_target_id' => $target->id,
                ]);

                return $target;
            }

            $target->update(['publish_requested_at' => now()]);

            return $target;
        });

        if ($unknownOutcome && $target !== null) {
            if ($post = $target->post) {
                app(ResolvePostPublishOutcomeAction::class)($post);
            }

            return null;
        }

        return $target;
    }
}
