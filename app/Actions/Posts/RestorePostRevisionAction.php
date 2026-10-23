<?php

namespace App\Actions\Posts;

use App\Models\Asset;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-5.3 — restore a post's content to an earlier revision. Brings back the caption, hashtags,
 * music, location, link, first comment, media format, the attached media (order + alt text) and each
 * still-present channel's overrides — through `UpdatePostAction`, so the restore is itself a save
 * (recorded as a new revision, vendor-held channels recalled and re-sent). It deliberately keeps the
 * current schedule and the current set of channels, so restoring old copy never silently unschedules
 * a post or resurrects a channel that was since removed.
 */
class RestorePostRevisionAction
{
    public function __construct(private readonly UpdatePostAction $updatePost) {}

    public function __invoke(Post $post, PostRevision $revision, ?User $user = null): Post
    {
        $snapshot = $revision->snapshot;
        $post->loadMissing('targets');

        $data = [
            'master_caption' => $snapshot['master_caption'] ?? null,
            'hashtags' => $snapshot['hashtags'] ?? [],
            'music' => $snapshot['music'] ?? null,
            'location' => $snapshot['location'] ?? null,
            'link_url' => $snapshot['link_url'] ?? null,
            'first_comment' => $snapshot['first_comment'] ?? null,
            'media_format' => $snapshot['media_format'] ?? null,
            ...$this->media($post, $snapshot['assets'] ?? []),
            'targets' => $this->targets($post, $snapshot['targets'] ?? []),
        ];

        $restored = ($this->updatePost)($post, $data, $user);

        Log::info('Post revision restored', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'revision_id' => $revision->id,
            'user_id' => $user?->id,
        ]);

        return $restored;
    }

    /**
     * The media set from the revision, in its saved order, filtered to assets that still exist,
     * with their alt text. Framing (crop) is left as it is on the current pivot.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function media(Post $post, array $assets): array
    {
        $ordered = collect($assets)->sortBy('sort_order')->values();
        $existing = Asset::query()
            ->whereIn('id', $ordered->pluck('asset_id')->map('intval'))
            ->pluck('id')
            ->all();

        $ids = $ordered->pluck('asset_id')->map('intval')->filter(fn ($id) => in_array($id, $existing, true))->values();

        return [
            'asset_ids' => $ids->all(),
            'asset_alt_texts' => $ordered
                ->filter(fn ($asset) => in_array((int) $asset['asset_id'], $existing, true))
                ->mapWithKeys(fn ($asset) => [(int) $asset['asset_id'] => $asset['alt_text'] ?? null])
                ->all(),
        ];
    }

    /**
     * The full channel set for the save: each current channel keeps its schedule; channels present
     * in the revision get their old overrides back, channels added since keep their current ones.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    private function targets(Post $post, array $targets): array
    {
        $fromRevision = collect($targets)->keyBy(fn ($target) => (int) $target['social_account_id']);

        return array_values($post->targets
            ->map(function (PostTarget $current) use ($fromRevision) {
                $old = $fromRevision->get($current->social_account_id);

                return [
                    'social_account_id' => $current->social_account_id,
                    'scheduled_local_date' => $current->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $current->scheduled_local_time,
                    'caption_override' => $old['caption_override'] ?? $current->caption_override,
                    'hashtags_override' => $old['hashtags_override'] ?? $current->hashtags_override,
                    'first_comment' => $old['first_comment'] ?? $current->first_comment,
                    'link_url' => $old['link_url'] ?? $current->link_url,
                    'format' => $old['format'] ?? $current->format,
                ];
            })
            ->all());
    }
}
