<?php

namespace App\Actions\Posts;

use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\VendorScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePostAction
{
    /**
     * Per-target fields a save may set; any not sent for a target are left as they are.
     */
    private const TARGET_FIELDS = ['caption_override', 'hashtags_override', 'first_comment', 'link_url', 'format'];

    /**
     * Update a post's content, per-target schedule and channel overrides, and attached media,
     * then refresh its §6 checklist snapshot (Step 0.10 editor UI).
     *
     * `$data['targets']` (when present) is the full set of channels, one entry per
     * `{social_account_id, scheduled_local_date, scheduled_local_time, ...overrides}`. Channels no
     * longer listed are removed; listed ones are updated in place (Step M-2.2: so a channel keeps
     * its overrides, publish status and vendor ids across saves) or created. Each target's
     * `scheduled_at_utc` is (re)computed independently from its own account's timezone (spec
     * §4.6), and a changed time re-arms its upcoming-post reminder.
     *
     * Step I-3.1 / M-2.2: a target's `caption_override`, `hashtags_override`, `first_comment` and
     * `link_url` override the post's master values (`null` = use the master; an empty string /
     * list = none on this channel). Step I-3.2 / M-2.3: `format` picks the target's format (`null`
     * = Auto).
     *
     * `$data['asset_ids']` (when present) fully replaces the attached media, in that order (the
     * carousel order). `$data['asset_crops']` (`{assetId: {x, y, zoom}}`) sets each image's framing
     * and `$data['asset_alt_texts']` (`{assetId: text}`) its alt text. When either is sent with
     * `asset_ids`, an attached image missing from it resets (centred / no alt text).
     * `$data['media_format']` stores the image format per channel.
     *
     * Step I-1.2: if the post is `scheduled`, its publish job is re-dispatched with a fresh id so
     * the new times apply and the old job no-ops.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        Post $post,
        array $data,
        EvaluatePostChecklistAction $evaluateChecklist = new EvaluatePostChecklistAction,
        TransitionPostStatusAction $transition = new TransitionPostStatusAction,
    ): Post {
        // Step I-3.6 / M-2.5: editing a post the vendor is holding for later recalls those
        // channels first (the edit is refused if the vendor says it's too late), and re-submits
        // them with the new content once saved — the old version never goes out.
        $vendor = app(VendorScheduleService::class);
        $recalled = $post->canBeRecalled() ? $vendor->recall($post, 'targets') : [];

        return DB::transaction(function () use ($post, $data, $evaluateChecklist, $transition, $vendor, $recalled) {
            DB::afterCommit(fn () => $vendor->resubmit($post, $recalled));

            if (array_key_exists('media_format', $data)) {
                $data['media_format'] = self::normalizeMediaFormat($data['media_format']);
            }

            $post->update(array_diff_key($data, array_flip(['targets', 'asset_ids', 'asset_crops', 'asset_alt_texts'])));

            // Campaigns enhancement C-3.2: a post that leaves its campaign frees the planned slot
            // it was started from, so that slot reads "Not started" again.
            if ($post->wasChanged('campaign_id')) {
                CampaignDeliverable::query()
                    ->where('post_id', $post->id)
                    ->where('campaign_id', '!=', $post->campaign_id ?? 0)
                    ->update(['post_id' => null]);
            }

            if (array_key_exists('targets', $data)) {
                $this->syncTargets($post, (array) ($data['targets'] ?? []));
            }

            $this->syncAssets($post, $data);

            $post->refresh();
            $post->update(['checklist_snapshot' => $evaluateChecklist($post)]);

            if (array_key_exists('targets', $data)) {
                ReschedulePostAction::redispatchIfScheduled($post, $transition);
            }

            Log::info('Post updated', [
                'post_id' => $post->id,
                'target_count' => $post->targets()->count(),
                'customized_target_count' => $post->targets->filter(fn (PostTarget $target) => $target->isCustomized())->count(),
                'media_format' => $post->media_format,
            ]);

            return $post->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    private function syncTargets(Post $post, array $targets): void
    {
        $existing = $post->targets()->get()->keyBy('social_account_id');
        $keep = [];

        foreach ($targets as $input) {
            $account = SocialAccount::query()->whereKey((int) $input['social_account_id'])->firstOrFail();
            $keep[] = $account->id;

            $date = filled($input['scheduled_local_date'] ?? null) ? (string) $input['scheduled_local_date'] : null;
            $time = filled($input['scheduled_local_time'] ?? null) ? (string) $input['scheduled_local_time'] : null;

            $attributes = [
                'scheduled_local_date' => $date,
                'scheduled_local_time' => $time,
                'scheduled_at_utc' => $date !== null && $time !== null
                    ? Carbon::parse($date.' '.$time, $account->timezone)->utc()
                    : null,
            ];

            foreach (self::TARGET_FIELDS as $field) {
                if (array_key_exists($field, $input)) {
                    $attributes[$field] = self::normalizeTargetField($field, $input[$field]);
                }
            }

            /** @var PostTarget|null $target */
            $target = $existing->get($account->id);

            if ($target === null) {
                $post->targets()->create(['social_account_id' => $account->id, ...$attributes]);

                continue;
            }

            $target->fill($attributes);

            if ($target->isDirty('scheduled_at_utc') || $target->isDirty('scheduled_local_date')) {
                $target->reminder_sent_at = null;
            }

            $target->save();
        }

        $post->targets()->whereNotIn('social_account_id', $keep)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAssets(Post $post, array $data): void
    {
        $crops = is_array($data['asset_crops'] ?? null) ? $data['asset_crops'] : null;
        $altTexts = is_array($data['asset_alt_texts'] ?? null) ? $data['asset_alt_texts'] : null;

        if (array_key_exists('asset_ids', $data)) {
            $attach = [];

            foreach (array_values((array) ($data['asset_ids'] ?? [])) as $order => $assetId) {
                $assetId = (int) $assetId;
                $attach[$assetId] = ['sort_order' => $order];

                if ($crops !== null) {
                    $attach[$assetId] += self::pivotFraming($crops[$assetId] ?? null);
                }

                if ($altTexts !== null) {
                    $attach[$assetId]['alt_text'] = self::nullableText($altTexts[$assetId] ?? null);
                }
            }

            $post->assets()->sync($attach);

            return;
        }

        foreach ($crops ?? [] as $assetId => $crop) {
            $post->assets()->updateExistingPivot((int) $assetId, self::pivotFraming($crop));
        }

        foreach ($altTexts ?? [] as $assetId => $text) {
            $post->assets()->updateExistingPivot((int) $assetId, ['alt_text' => self::nullableText($text)]);
        }
    }

    private static function normalizeTargetField(string $field, mixed $value): mixed
    {
        if ($field === 'hashtags_override') {
            if (is_string($value)) {
                $value = $value === '' ? null : json_decode($value, true);
            }

            if (! is_array($value)) {
                return null;
            }

            return array_values(array_unique(array_filter(
                array_map(fn ($tag) => ltrim(trim((string) $tag), '#'), $value),
                fn ($tag) => $tag !== '',
            )));
        }

        if ($field === 'format') {
            return filled($value) ? (string) $value : null;
        }

        // Overrides: null = use the master, '' = none on this channel.
        return $value === null ? null : trim((string) $value);
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
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
