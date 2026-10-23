<?php

namespace App\Actions\Posts;

use App\Enums\PostRecurrence;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-5.8 (Phase 11 / I-4.7) — reuse a post without reinventing a content type: a recycled post
 * is just a new `Post` seeded from an old one's fields.
 *
 * Two callers, one copy routine:
 *
 * - **Manual recycle** (`$recurrence === null`): an evergreen, published post is copied as a fresh
 *   `idea`. Content, media and the target accounts (with their per-channel overrides, I-4.7) carry
 *   over, but **not** the schedule — accounts and timezones may have changed, so the user re-picks
 *   dates. Rejected unless the source is flagged `is_recyclable`.
 * - **Recurrence** (`$recurrence` set): the next occurrence of a recurring post is dropped as a
 *   `draft` (never straight to `scheduled` — a human always reviews it). Here the schedule **is**
 *   copied, each target's local date advanced by one interval (month-end-safe) and its
 *   `scheduled_at_utc` re-derived in the account's timezone, so a DST change keeps the local time.
 *
 * Either way the copy records `recycled_from_post_id`, so a suggestion never fires twice and the
 * recurrence sweep can find a chain's leaf. Wrapped in `DB::transaction`.
 */
class RecyclePostAction
{
    public function __invoke(Post $source, ?User $user = null, ?PostRecurrence $recurrence = null): Post
    {
        if ($recurrence === null && ! $source->is_recyclable) {
            throw ValidationException::withMessages([
                'post' => __('This post isn’t marked as reusable.'),
            ]);
        }

        $source->loadMissing(['targets.socialAccount', 'assets']);

        return DB::transaction(function () use ($source, $user, $recurrence) {
            $copy = Post::create([
                'org_id' => $source->org_id,
                'client_id' => $source->client_id,
                'campaign_id' => $source->campaign_id,
                'created_by' => $user?->id,
                'status' => $recurrence !== null ? PostStatus::Draft : PostStatus::Idea,
                'approval_mode' => $source->approval_mode,
                'master_caption' => $source->master_caption,
                'hashtags' => $source->hashtags,
                'music' => $source->music,
                'location' => $source->location,
                'link_url' => $source->link_url,
                'first_comment' => $source->first_comment,
                'media_format' => $source->media_format,
                'cover_asset_id' => $source->cover_asset_id,
                'thumb_offset_ms' => $source->thumb_offset_ms,
                'recycled_from_post_id' => $source->id,
                // A recurring post's children keep recurring; a manual recycle is a one-off.
                'recurrence' => $recurrence,
            ]);

            // The same assets, order, crop and alt text — not re-uploaded.
            $pivot = $source->assets->mapWithKeys(function ($asset) {
                /** @var object{crop_x?: float|null, crop_y?: float|null, crop_zoom?: float|null, alt_text?: string|null, sort_order?: int|null}|null $data */
                $data = $asset->getRelationValue('pivot');

                return [$asset->id => [
                    'crop_x' => $data->crop_x ?? null,
                    'crop_y' => $data->crop_y ?? null,
                    'crop_zoom' => $data->crop_zoom ?? null,
                    'alt_text' => $data->alt_text ?? null,
                    'sort_order' => $data->sort_order ?? null,
                ]];
            })->all();

            if ($pivot !== []) {
                $copy->assets()->attach($pivot);
            }

            foreach ($source->targets as $target) {
                $data = [
                    'social_account_id' => $target->social_account_id,
                    'caption_override' => $target->caption_override,
                    'hashtags_override' => $target->hashtags_override,
                    'first_comment' => $target->first_comment,
                    'link_url' => $target->link_url,
                    'format' => $target->format,
                ];

                // Recurrence advances each dated target by one interval; a manual recycle drops the
                // schedule entirely.
                if ($recurrence !== null && $target->scheduled_local_date !== null) {
                    $date = $recurrence->advance($target->scheduled_local_date)->toDateString();
                    $data['scheduled_local_date'] = $date;
                    $data['scheduled_local_time'] = $target->scheduled_local_time;
                    $data['scheduled_at_utc'] = filled($target->scheduled_local_time)
                        ? Carbon::parse($date.' '.$target->scheduled_local_time, $target->socialAccount->timezone)->utc()
                        : null;
                }

                $copy->targets()->create($data);
            }

            Log::info('Post recycled', [
                'org_id' => $source->org_id,
                'source_post_id' => $source->id,
                'post_id' => $copy->id,
                'user_id' => $user?->id,
                'recurrence' => $recurrence?->value,
                'target_count' => $copy->targets()->count(),
            ]);

            return $copy;
        });
    }
}
