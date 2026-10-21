<?php

namespace App\Actions\Publishing;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Support\Publishing\VendorPostRef;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.5 — the single seam both the Upload-Post webhook and the poll fallback go through to
 * record a vendor's final outcome for a previously accepted publish (`PostTarget::status ===
 * Pending` with an `external_post_id`, i.e. accepted by `PublishPostTargetJob` but not yet
 * confirmed). Marks the target published/failed, then lets `ResolvePostPublishOutcomeAction`
 * decide (Step I-1.3) whether the post is finished — `published`, `partially_published` or
 * `failed` — via the same `TransitionPostStatusAction` used everywhere else, so the activity log
 * stays complete.
 *
 * Step M-3.1: a published target also records when it went live (`published_at`) and whatever the
 * vendor told us about the live post (`VendorPostRef`), so its engagement can be fetched later.
 */
class SyncPublishStatusAction
{
    public function __invoke(
        PostTarget $target,
        bool $ok,
        ?string $error = null,
        ResolvePostPublishOutcomeAction $resolve = new ResolvePostPublishOutcomeAction,
        ?VendorPostRef $ref = null,
    ): void {
        $target->update([
            'status' => $ok ? PostTargetStatus::Published : PostTargetStatus::Failed,
            'error' => $ok ? null : $error,
            ...($ok ? ['published_at' => $target->published_at ?? now(), ...($ref?->toColumns() ?? [])] : []),
        ]);

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

        $outcome = $resolve($post);

        if ($outcome !== null) {
            Log::info('SyncPublishStatusAction: post finalized from vendor sync', [
                'post_id' => $post->id,
                'status' => $outcome->value,
            ]);
        }
    }
}
