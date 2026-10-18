<?php

namespace App\Actions\Publishing;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step I-1.3 — "Retry failed targets": resends only the `failed` targets of a `failed` or
 * `partially_published` post. Targets that already went out are never touched. Each retried
 * target gets a fresh `publish_attempt_id` (its idempotency key), the post moves back to
 * `publishing` (logged with which channels are being retried), and the target jobs are batched
 * after commit.
 */
class RetryFailedPostTargetsAction
{
    public function __construct(
        private readonly TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ) {}

    public function __invoke(Post $post, User $user): Post
    {
        return DB::transaction(function () use ($post, $user): Post {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true)) {
                throw new InvalidPostTransitionException($locked->id, $locked->status, PostStatus::Publishing);
            }

            $failed = $locked->targets()->with('socialAccount')->where('status', PostTargetStatus::Failed)->get();

            if ($failed->isEmpty()) {
                throw ValidationException::withMessages([
                    'targets' => __('There are no failed channels to retry.'),
                ]);
            }

            foreach ($failed as $target) {
                $target->update([
                    'status' => PostTargetStatus::Pending,
                    'external_post_id' => null,
                    'error' => null,
                    'publish_attempt_id' => (string) Str::uuid(),
                    'publish_requested_at' => null,
                ]);
            }

            $channels = $failed
                ->map(fn (PostTarget $target) => $target->socialAccount
                    ? "{$target->socialAccount->platform->value} ({$target->socialAccount->handle})"
                    : "target #{$target->id}")
                ->implode(', ');

            $locked = ($this->transition)(
                $locked,
                PostStatus::Publishing,
                $user,
                __('Retrying failed channels: :channels', ['channels' => $channels]),
            );

            DB::afterCommit(fn () => PublishPostJob::dispatchTargets($locked, $failed->map->fresh()->filter()->values()));

            Log::info('Retrying failed post targets', [
                'post_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $user->id,
                'post_target_ids' => $failed->pluck('id')->all(),
            ]);

            return $locked;
        });
    }
}
