<?php

namespace App\Jobs;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Notifications\PostPublishFailed;
use App\Services\PostNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.4 — the queued job dispatched once a post is transitioned to `scheduled` (see
 * `TransitionPostStatusAction`). Nothing calls the vendor before this job runs, and this job
 * only runs once the §6 checklist has already passed (enforced server-side by
 * `TransitionPostRequest` before the `scheduled` transition is even allowed).
 *
 * Moves the post to `publishing`, calls `PublishAdapter::publish()` for every target's
 * `SocialAccount`, records each target's `TargetResult` (external id or raw vendor error), then
 * moves the post to `published` (all targets ok) or `failed` (any target failed).
 */
class PublishPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $postId) {}

    public function handle(
        PublishAdapter $adapter,
        TransitionPostStatusAction $transition,
        PostNotificationService $notifications = new PostNotificationService,
    ): void {
        $post = Post::with('targets.socialAccount', 'assets')->find($this->postId);

        if (! $post) {
            Log::warning('PublishPostJob: post not found', ['post_id' => $this->postId]);

            return;
        }

        // The post may have moved on (e.g. manually reverted to draft) since this job was
        // enqueued — never publish a post that isn't still in the `scheduled` state.
        if ($post->status !== PostStatus::Scheduled) {
            Log::info('PublishPostJob: post is no longer scheduled, skipping', [
                'post_id' => $post->id,
                'status' => $post->status->value,
            ]);

            return;
        }

        $post = $transition($post, PostStatus::Publishing, null);

        $accounts = $post->targets
            ->map(fn ($target) => $target->socialAccount)
            ->filter()
            ->values();

        Log::info('PublishPostJob: publishing post', [
            'post_id' => $post->id,
            'target_count' => $accounts->count(),
        ]);

        $results = $adapter->publish($post, $accounts);

        DB::transaction(function () use ($post, $results) {
            foreach ($results as $result) {
                $post->targets->firstWhere('social_account_id', $result->accountId)?->update([
                    'status' => $result->ok ? PostTargetStatus::Published : PostTargetStatus::Failed,
                    'external_post_id' => $result->externalPostId,
                    'error' => $result->error,
                ]);
            }
        });

        $allOk = $results->isNotEmpty() && $results->every(fn ($result) => $result->ok);

        if ($allOk) {
            $transition($post, PostStatus::Published, null);

            Log::info('PublishPostJob: post published successfully', ['post_id' => $post->id]);

            return;
        }

        $failures = $results->reject(fn ($result) => $result->ok)->values();

        // Surface the vendor error(s) on the activity log entry (rather than only next to the
        // relevant target/social account) so it's visible in one place alongside every other
        // status change, and so it's still there even after the target rows are reset on retry.
        $errorNote = $failures
            ->map(function ($result) use ($post) {
                $account = $post->targets->firstWhere('social_account_id', $result->accountId)?->socialAccount;
                $label = $account ? "{$account->platform->value} ({$account->handle})" : "account #{$result->accountId}";

                return "{$label}: {$result->error}";
            })
            ->implode("\n");

        $transition($post, PostStatus::Failed, null, $errorNote ?: null);

        $notifications->send($notifications->orgRecipients($post), new PostPublishFailed($post));

        Log::error('PublishPostJob: post publish failed for one or more targets', [
            'post_id' => $post->id,
            'errors' => $failures
                ->map(fn ($result) => ['account_id' => $result->accountId, 'error' => $result->error])
                ->values()
                ->all(),
        ]);
    }
}
