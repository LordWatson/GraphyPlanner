<?php

namespace App\Jobs;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hands a `scheduled` post to the vendor as soon as it's scheduled, so Upload-Post holds each
 * channel with its own `scheduled_date` — the post stays `scheduled` here until its time comes.
 * Dispatched (undelayed) next to the delayed `PublishPostJob` by
 * `TransitionPostStatusAction::dispatchPublishJob()`.
 *
 * Only channels due more than `LEAD_MINUTES` from now are sent: the adapter publishes anything
 * due within a minute immediately, so those are left to `PublishPostJob` at the post's time. When
 * that job runs it moves the post to `publishing` and skips every channel the vendor already has.
 *
 * Carries the post's `publish_dispatch_id` like `PublishPostJob`: once the post leaves `scheduled`
 * or is rescheduled (new id) this no-ops. Unscheduling, moving, editing, pausing or deleting a
 * post cancels the held channels first (`VendorScheduleService`), and the new schedule's job sends
 * them again.
 */
class SendScheduledPostToVendorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Channels due sooner than this are published at their time by `PublishPostJob` instead. */
    public const LEAD_MINUTES = 5;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(public readonly int $postId, public readonly string $dispatchId)
    {
        $this->onQueue(Queues::PUBLISHING);
    }

    public function uniqueId(): string
    {
        return $this->postId.':'.$this->dispatchId;
    }

    public function handle(): void
    {
        /** @var array{0: Post, 1: Collection<int, PostTarget>}|null $claimed */
        $claimed = DB::transaction(fn (): ?array => $this->claim());

        if ($claimed === null) {
            return;
        }

        [$post, $targets] = $claimed;

        Log::info('SendScheduledPostToVendorJob: sending scheduled post to the vendor', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'target_count' => $targets->count(),
        ]);

        PublishPostJob::dispatchTargets($post, $targets);
    }

    /**
     * Lock the post, make sure this job still owns its schedule, and give every channel the vendor
     * should hold a fresh attempt id. Null when there's nothing to send.
     *
     * @return array{0: Post, 1: Collection<int, PostTarget>}|null
     */
    private function claim(): ?array
    {
        /** @var Post|null $post */
        $post = Post::query()->whereKey($this->postId)->lockForUpdate()->first();

        if (! $post || $post->status !== PostStatus::Scheduled || $post->publish_dispatch_id !== $this->dispatchId) {
            Log::info('SendScheduledPostToVendorJob: post no longer on this schedule, skipping', [
                'post_id' => $this->postId,
                'job_dispatch_id' => $this->dispatchId,
                'status' => $post?->status->value,
            ]);

            return null;
        }

        if ($reason = PublishPostJob::pausedReason($post)) {
            Log::warning('SendScheduledPostToVendorJob: '.$reason.', skipping', [
                'post_id' => $post->id,
                'org_id' => $post->org_id,
                'client_id' => $post->client_id,
            ]);

            return null;
        }

        $targets = $post->targets()
            ->where('status', PostTargetStatus::Pending)
            ->whereNull('external_post_id')
            ->whereNull('publish_requested_at')
            ->where('scheduled_at_utc', '>', now()->addMinutes(self::LEAD_MINUTES))
            ->get();

        if ($targets->isEmpty()) {
            Log::info('SendScheduledPostToVendorJob: no channel far enough ahead to hand over', ['post_id' => $post->id]);

            return null;
        }

        foreach ($targets as $target) {
            $target->update(['publish_attempt_id' => (string) Str::uuid(), 'error' => null]);
        }

        return [$post, $targets];
    }
}
