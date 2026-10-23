<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-5.2 — duplicate a post. Copies the content (caption, hashtags, music, location, link, first
 * comment, media format, cover), its media (the `post_assets` pivot, crop and alt text) and its
 * target accounts with their per-channel overrides — but **never** the schedule, the publish state or
 * the activity/approvals. The copy starts life as a fresh `draft` for the same client, so nothing
 * goes out by accident. Opens in the editor afterwards (the controller redirects).
 */
class DuplicatePostAction
{
    public function __invoke(Post $source, User $user): Post
    {
        $source->loadMissing(['targets', 'assets']);

        return DB::transaction(function () use ($source, $user) {
            $copy = Post::create([
                'org_id' => $source->org_id,
                'client_id' => $source->client_id,
                'campaign_id' => $source->campaign_id,
                'created_by' => $user->id,
                'status' => PostStatus::Draft,
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
            ]);

            // The same assets, in the same order, with their crop and alt text — but not re-uploaded.
            $pivot = $source->assets->mapWithKeys(function ($asset) {
                /** @var object{crop_x?: float|null, crop_y?: float|null, crop_zoom?: float|null, alt_text?: string|null, sort_order?: int|null}|null $data */
                $data = $asset->getRelationValue('pivot');

                return [
                    $asset->id => [
                        'crop_x' => $data->crop_x ?? null,
                        'crop_y' => $data->crop_y ?? null,
                        'crop_zoom' => $data->crop_zoom ?? null,
                        'alt_text' => $data->alt_text ?? null,
                        'sort_order' => $data->sort_order ?? null,
                    ],
                ];
            })->all();

            if ($pivot !== []) {
                $copy->assets()->attach($pivot);
            }

            // Target accounts with their content overrides, but no schedule and no publish state.
            foreach ($source->targets as $target) {
                $copy->targets()->create([
                    'social_account_id' => $target->social_account_id,
                    'caption_override' => $target->caption_override,
                    'hashtags_override' => $target->hashtags_override,
                    'first_comment' => $target->first_comment,
                    'link_url' => $target->link_url,
                    'format' => $target->format,
                ]);
            }

            Log::info('Post duplicated', [
                'org_id' => $source->org_id,
                'source_post_id' => $source->id,
                'post_id' => $copy->id,
                'user_id' => $user->id,
                'target_count' => $copy->targets()->count(),
            ]);

            return $copy;
        });
    }
}
