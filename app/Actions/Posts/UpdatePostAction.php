<?php

namespace App\Actions\Posts;

use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePostAction
{
    /**
     * Update a post's content, per-target schedule, and attached media, then refresh its §6
     * checklist snapshot (Step 0.10 editor UI).
     *
     * `$data['targets']` (when present) fully replaces the post's `PostTarget`s, one row per
     * `{social_account_id, scheduled_local_date, scheduled_local_time}` — each target's
     * `scheduled_at_utc` is (re)computed independently from its own social account's timezone
     * (spec §4.6). `$data['asset_ids']` (when present) fully replaces the post's attached assets.
     *
     * Step I-1.2: if the post is `scheduled` (content edits are policy-locked there today, but the
     * action stays safe if that changes), its publish job is re-dispatched with a fresh id so the
     * new times apply and the old job no-ops.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        Post $post,
        array $data,
        EvaluatePostChecklistAction $evaluateChecklist = new EvaluatePostChecklistAction,
        TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ): Post {
        return DB::transaction(function () use ($post, $data, $evaluateChecklist, $transition) {
            $post->update(array_diff_key($data, ['targets' => null, 'asset_ids' => null]));

            // Campaigns enhancement C-3.2: a post that leaves its campaign frees the planned slot
            // it was started from, so that slot reads "Not started" again.
            if ($post->wasChanged('campaign_id')) {
                CampaignDeliverable::query()
                    ->where('post_id', $post->id)
                    ->where('campaign_id', '!=', $post->campaign_id ?? 0)
                    ->update(['post_id' => null]);
            }

            if (array_key_exists('targets', $data)) {
                $post->targets()->delete();

                foreach ($data['targets'] ?? [] as $target) {
                    $account = SocialAccount::findOrFail($target['social_account_id']);

                    $scheduledAtUtc = null;

                    if (! empty($target['scheduled_local_date']) && ! empty($target['scheduled_local_time'])) {
                        $scheduledAtUtc = Carbon::parse(
                            $target['scheduled_local_date'].' '.$target['scheduled_local_time'],
                            $account->timezone,
                        )->utc();
                    }

                    $post->targets()->create([
                        'social_account_id' => $account->id,
                        'scheduled_local_date' => $target['scheduled_local_date'] ?? null,
                        'scheduled_local_time' => $target['scheduled_local_time'] ?? null,
                        'scheduled_at_utc' => $scheduledAtUtc,
                    ]);
                }
            }

            if (array_key_exists('asset_ids', $data)) {
                $post->assets()->sync($data['asset_ids'] ?? []);
            }

            $post->refresh();
            $post->update(['checklist_snapshot' => $evaluateChecklist($post)]);

            if (array_key_exists('targets', $data)) {
                ReschedulePostAction::redispatchIfScheduled($post, $transition);
            }

            Log::info('Post updated', [
                'post_id' => $post->id,
                'target_count' => $post->targets()->count(),
            ]);

            return $post->refresh();
        });
    }
}
