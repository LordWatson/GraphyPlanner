<?php

namespace App\Actions\Publishing;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.5 — the single seam both the Upload-Post webhook and the poll fallback go through to
 * record a vendor's final outcome for a previously accepted publish (`PostTarget::status ===
 * Pending`, e.g. a `TargetResult::ok` acceptance from `PublishPostJob` before the vendor
 * confirms delivery). Marks the target published/failed, then — once every target on the post
 * has resolved — moves the post itself from `publishing` to `published`/`failed` via the same
 * `TransitionPostStatusAction` used everywhere else, so the activity log stays complete.
 */
class SyncPublishStatusAction
{
    public function __invoke(
        PostTarget $target,
        bool $ok,
        ?string $error = null,
        TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ): void {
        DB::transaction(function () use ($target, $ok, $error) {
            $target->update([
                'status' => $ok ? PostTargetStatus::Published : PostTargetStatus::Failed,
                'error' => $error,
            ]);
        });

        $post = $target->post()->first();

        if (! $post) {
            Log::warning('SyncPublishStatusAction: post not found for target', ['post_target_id' => $target->id]);

            return;
        }

        Log::info('SyncPublishStatusAction: target status synced from vendor', [
            'post_id' => $post->id,
            'post_target_id' => $target->id,
            'ok' => $ok,
        ]);

        // Only a post still in `publishing` can be finalized here — if it already moved on
        // (e.g. `PublishPostJob` already resolved every target synchronously, or a human
        // reverted it) there's nothing left to do.
        if ($post->status !== PostStatus::Publishing) {
            return;
        }

        $targets = $post->targets()->get();

        if ($targets->contains(fn (PostTarget $t) => $t->status === PostTargetStatus::Pending)) {
            // Still waiting on one or more other targets to report in.
            return;
        }

        $allOk = $targets->isNotEmpty() && $targets->every(fn (PostTarget $t) => $t->status === PostTargetStatus::Published);

        $transition($post, $allOk ? PostStatus::Published : PostStatus::Failed, null);

        Log::info('SyncPublishStatusAction: post finalized from vendor sync', [
            'post_id' => $post->id,
            'status' => $allOk ? PostStatus::Published->value : PostStatus::Failed->value,
        ]);
    }
}
