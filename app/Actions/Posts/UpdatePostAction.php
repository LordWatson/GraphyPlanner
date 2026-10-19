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
     * `$data['media_format']` stores the image format per channel (unset channels fall back to
     * their default), and `$data['asset_crops']` (`{assetId: {x, y, zoom}}`) each image's framing.
     * When crops are sent with `asset_ids`, an attached image missing from them resets to centred.
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
            if (array_key_exists('media_format', $data)) {
                $data['media_format'] = self::normalizeMediaFormat($data['media_format']);
            }

            $post->update(array_diff_key($data, ['targets' => null, 'asset_ids' => null, 'asset_crops' => null]));

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

            $crops = $data['asset_crops'] ?? null;

            if (array_key_exists('asset_ids', $data)) {
                $attach = [];

                foreach ((array) ($data['asset_ids'] ?? []) as $assetId) {
                    $attach[(int) $assetId] = is_array($crops) ? self::pivotFraming($crops[$assetId] ?? null) : [];
                }

                $post->assets()->sync($attach);
            } elseif (is_array($crops)) {
                foreach ($crops as $assetId => $crop) {
                    $post->assets()->updateExistingPivot((int) $assetId, self::pivotFraming($crop));
                }
            }

            $post->refresh();
            $post->update(['checklist_snapshot' => $evaluateChecklist($post)]);

            if (array_key_exists('targets', $data)) {
                ReschedulePostAction::redispatchIfScheduled($post, $transition);
            }

            Log::info('Post updated', [
                'post_id' => $post->id,
                'target_count' => $post->targets()->count(),
                'media_format' => $post->media_format,
            ]);

            return $post->refresh();
        });
    }

    /**
     * Keep only channels with a format set; an empty map is stored as null (all defaults).
     *
     * @return array<string, string>|null
     */
    private static function normalizeMediaFormat(mixed $mediaFormat): ?array
    {
        $formats = array_filter(is_array($mediaFormat) ? $mediaFormat : [], fn ($ratio) => is_string($ratio) && $ratio !== '');

        return $formats === [] ? null : $formats;
    }

    /**
     * `post_assets` crop columns for one image; centred at 1× when no framing was sent.
     *
     * @return array{crop_x: float, crop_y: float, crop_zoom: float}
     */
    private static function pivotFraming(mixed $crop): array
    {
        $crop = is_array($crop) ? $crop : [];

        return [
            'crop_x' => round((float) ($crop['x'] ?? 0.5), 4),
            'crop_y' => round((float) ($crop['y'] ?? 0.5), 4),
            'crop_zoom' => round((float) ($crop['zoom'] ?? 1), 2),
        ];
    }
}
