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
 *
 * Step I-1.3: a target still `pending` past `publishing.delivery_timeout_hours` (accepted but never
 * confirmed, or never sent at all) is failed with "No delivery confirmation after N hours", so a
 * post can't sit in `publishing` forever — it lands on Home as failed/partially published. A
 * channel the vendor holds for a later time is skipped until it's due, and its timeout counts
 * from then.
 * Step I-1.8: rows are walked with `lazyById()` instead of loaded all at once.
 */
class PollPendingPublishStatusesAction
{
    public function __invoke(
        PublishAdapter $adapter,
        SyncPublishStatusAction $sync,
        int $olderThanMinutes = 5,
    ): int {
        $checked = 0;
        $timeoutHours = max(1, (int) config('publishing.delivery_timeout_hours', 24));
        $timedOutBefore = now()->subHours($timeoutHours);

        $targets = PostTarget::query()
            ->where('status', PostTargetStatus::Pending)
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->whereHas('post', fn ($query) => $query->where('status', PostStatus::Publishing))
            ->with('socialAccount')
            ->lazyById(200);

        foreach ($targets as $target) {
            // Held by the vendor until a later time: nothing to confirm yet, and the timeout only
            // starts once it's due.
            if ($target->scheduled_at_utc !== null && $target->scheduled_at_utc->isFuture()) {
                continue;
            }

            $waitingSince = $target->scheduled_at_utc !== null && $target->updated_at !== null
                ? $target->updated_at->max($target->scheduled_at_utc)
                : $target->updated_at;

            if ($waitingSince !== null && $waitingSince->lessThanOrEqualTo($timedOutBefore)) {
                Log::warning('PollPendingPublishStatusesAction: delivery confirmation timed out', [
                    'post_target_id' => $target->id,
                    'post_id' => $target->post_id,
                    'timeout_hours' => $timeoutHours,
                ]);

                $sync($target, false, __('No delivery confirmation after :hours hours.', ['hours' => $timeoutHours]));

                continue;
            }

            $account = $target->socialAccount;

            if (! $account || ! $target->external_post_id) {
                // Not sent yet (its target job is still queued) — the timeout above catches it if
                // it never is.
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

            $sync($target, $result->ok, $result->error, ref: $result->ref);
        }

        return $checked;
    }
}
