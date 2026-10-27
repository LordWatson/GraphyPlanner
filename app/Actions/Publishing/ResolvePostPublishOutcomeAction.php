<?php

namespace App\Actions\Publishing;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Push\SendPublishFailurePushAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Notifications\PostPublishFailed;
use App\Services\PostNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step I-1.3 — the one place a `publishing` post is finalized from its targets' outcomes. Called
 * after every target-level change: `PublishPostTargetJob` (vendor accepted/rejected/crashed),
 * `SyncPublishStatusAction` (webhook or poll confirmed delivery) and the stuck-target timeout.
 *
 * While any target is still `pending` (not yet sent, or accepted and awaiting confirmation) the
 * post stays `publishing`. Once every target has resolved:
 * - all published → `published`;
 * - none published (or no targets) → `failed`;
 * - a mix → `partially_published`, so one platform's failure never hides the others' success,
 *   and "Retry failed targets" resends only the failed ones.
 *
 * Failures are written to the activity log entry and the org is notified (after commit).
 */
class ResolvePostPublishOutcomeAction
{
    public function __construct(
        private readonly TransitionPostStatusAction $transition = new TransitionPostStatusAction,
        private readonly PostNotificationService $notifications = new PostNotificationService,
    ) {}

    public function __invoke(Post $post): ?PostStatus
    {
        return DB::transaction(function () use ($post): ?PostStatus {
            /** @var Post|null $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->first();

            // Only a post still `publishing` can be finalized — if it already moved on (resolved
            // by a concurrent target, or reverted by a human) there's nothing left to do.
            if (! $locked || $locked->status !== PostStatus::Publishing) {
                return null;
            }

            $targets = $locked->targets()->with('socialAccount')->get();

            if ($targets->contains(fn (PostTarget $target) => $target->status === PostTargetStatus::Pending)) {
                return null;
            }

            $failed = $targets->where('status', PostTargetStatus::Failed)->values();
            $publishedCount = $targets->where('status', PostTargetStatus::Published)->count();

            $outcome = match (true) {
                $publishedCount === 0 => PostStatus::Failed,
                $failed->isEmpty() => PostStatus::Published,
                default => PostStatus::PartiallyPublished,
            };

            $note = $failed
                ->map(function (PostTarget $target): string {
                    $account = $target->socialAccount;
                    $label = $account ? "{$account->platform->value} ({$account->handle})" : "target #{$target->id}";

                    return "{$label}: {$target->error}";
                })
                ->implode("\n");

            ($this->transition)($locked, $outcome, null, $note !== '' ? $note : null);

            if ($outcome !== PostStatus::Published) {
                DB::afterCommit(function () use ($locked): void {
                    $this->notifications->send(
                        $this->notifications->orgRecipients($locked),
                        new PostPublishFailed($locked),
                    );

                    // Step CG-1.3: a business/creator workspace also gets a phone push (resolved from
                    // the container so the bound transport/fake is used). A no-op for agency orgs and
                    // when push is unconfigured.
                    app(SendPublishFailurePushAction::class)($locked);
                });

                Log::error('Post publish finished with failed targets', [
                    'post_id' => $locked->id,
                    'org_id' => $locked->org_id,
                    'status' => $outcome->value,
                    'failed_target_ids' => $failed->pluck('id')->all(),
                ]);
            } else {
                Log::info('Post published to every target', ['post_id' => $locked->id, 'org_id' => $locked->org_id]);
            }

            return $outcome;
        });
    }
}
