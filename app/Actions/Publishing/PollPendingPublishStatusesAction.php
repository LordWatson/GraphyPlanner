<?php

namespace App\Actions\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.5 fallback poll: for every `PostTarget` still `pending` on a post stuck in
 * `publishing` (i.e. its webhook never arrived, or never will for a vendor that doesn't send
 * one), asks `PublishAdapter::checkStatus()` for the vendor's current verdict and, if resolved,
 * finalizes it through `SyncPublishStatusAction` exactly like the webhook does.
 */
class PollPendingPublishStatusesAction
{
    public function __invoke(
        PublishAdapter $adapter,
        SyncPublishStatusAction $sync,
        int $olderThanMinutes = 5,
    ): int {
        $targets = PostTarget::query()
            ->where('status', PostTargetStatus::Pending)
            ->whereNotNull('external_post_id')
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->whereHas('post', fn ($query) => $query->where('status', PostStatus::Publishing))
            ->with('socialAccount')
            ->get();

        $checked = 0;

        foreach ($targets as $target) {
            $account = $target->socialAccount;

            if (! $account || ! $target->external_post_id) {
                continue;
            }

            $checked++;

            $result = $adapter->checkStatus($account, $target->external_post_id);

            if ($result === null) {
                // Still processing on the vendor's side — try again next run.
                continue;
            }

            Log::info('PollPendingPublishStatusesAction: resolved a pending publish target', [
                'post_target_id' => $target->id,
                'post_id' => $target->post_id,
                'ok' => $result->ok,
            ]);

            $sync($target, $result->ok, $result->error);
        }

        return $checked;
    }
}
