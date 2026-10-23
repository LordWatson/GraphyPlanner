<?php

namespace App\Services\Posts;

use App\Models\Post;
use App\Models\PostRevision;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-5.3 — the content history for a post. Builds a content snapshot (never the schedule or
 * publish state), records one per save that actually changes the content (so autosave doesn't spam
 * identical rows), and diffs two snapshots into a plain "what changed" payload for the editor's
 * history drawer and the client's "what changed since you last saw it".
 */
class PostRevisionService
{
    /**
     * The content fields a revision captures — caption, hashtags and the rest of what a client
     * reviews. The schedule, status and vendor ids are deliberately excluded.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Post $post): array
    {
        $post->loadMissing(['targets.socialAccount', 'assets']);

        return [
            'master_caption' => $post->master_caption,
            'hashtags' => $post->hashtags ?? [],
            'music' => $post->music,
            'location' => $post->location,
            'link_url' => $post->link_url,
            'first_comment' => $post->first_comment,
            'media_format' => $post->media_format,
            'targets' => $post->targets
                ->sortBy('social_account_id')
                ->map(fn (PostTarget $target) => [
                    'social_account_id' => $target->social_account_id,
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'caption_override' => $target->caption_override,
                    'hashtags_override' => $target->hashtags_override,
                    'first_comment' => $target->first_comment,
                    'link_url' => $target->link_url,
                    'format' => $target->format,
                ])
                ->values()
                ->all(),
            'assets' => $post->assets
                ->map(function ($asset) {
                    /** @var object{alt_text?: string|null, sort_order?: int|null}|null $pivot */
                    $pivot = $asset->getRelationValue('pivot');

                    return [
                        'asset_id' => $asset->id,
                        'alt_text' => $pivot->alt_text ?? null,
                        'sort_order' => $pivot->sort_order ?? 0,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Record a revision for the post's current content. A plain save only writes one when the
     * content actually changed since the last revision; a `shared` checkpoint (the post entering
     * `waiting_client`) always marks the shown version — reusing the latest revision when its
     * content already matches, so history stays clean.
     */
    public function record(Post $post, ?User $user = null, bool $shared = false): ?PostRevision
    {
        $snapshot = $this->snapshot($post);
        $latest = $post->revisions()->latest('id')->first();
        $unchanged = $latest !== null && $latest->snapshot == $snapshot;

        if ($unchanged && ! $shared) {
            return null;
        }

        // Reaching here with $unchanged means this is a shared checkpoint of the latest content:
        // mark that revision as shown rather than duplicating it.
        if ($unchanged) {
            if ($latest->shared_at === null) {
                $latest->update(['shared_at' => now()]);
            }

            return $latest;
        }

        $revision = $post->revisions()->create([
            'org_id' => $post->org_id,
            'created_by' => $user?->id,
            'snapshot' => $snapshot,
            'shared_at' => $shared ? now() : null,
        ]);

        Log::info('Post revision recorded', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'revision_id' => $revision->id,
            'user_id' => $user?->id,
            'shared' => $shared,
        ]);

        return $revision;
    }

    /**
     * "What changed since the client last saw it": the diff between the two most recent shared
     * revisions (the previous review and this one). `null` when the post has only been shared once
     * (a first review, so there's nothing to compare against) or not at all.
     *
     * @return array<string, mixed>|null
     */
    public function changesSinceLastReview(Post $post): ?array
    {
        $shared = $post->revisions()
            ->whereNotNull('shared_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($shared->count() < 2) {
            return null;
        }

        $diff = $this->diff($shared[1]->snapshot, $shared[0]->snapshot);

        return $diff['is_empty'] === true ? null : $diff;
    }

    /**
     * A plain-language diff of two content snapshots. `null` sub-entries mean "no change there".
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    public function diff(array $old, array $new): array
    {
        $text = fn (string $key) => ($old[$key] ?? null) !== ($new[$key] ?? null)
            ? ['from' => $old[$key] ?? null, 'to' => $new[$key] ?? null]
            : null;

        $oldTags = array_map('strval', $old['hashtags'] ?? []);
        $newTags = array_map('strval', $new['hashtags'] ?? []);
        $added = array_values(array_diff($newTags, $oldTags));
        $removed = array_values(array_diff($oldTags, $newTags));

        $diff = [
            'caption' => $text('master_caption'),
            'first_comment' => $text('first_comment'),
            'link_url' => $text('link_url'),
            'hashtags' => ($added !== [] || $removed !== []) ? ['added' => $added, 'removed' => $removed] : null,
            'media' => $this->mediaDiff($old['assets'] ?? [], $new['assets'] ?? []),
            'channels' => $this->channelDiff($old['targets'] ?? [], $new['targets'] ?? []),
        ];

        $diff['is_empty'] = $diff['caption'] === null
            && $diff['first_comment'] === null
            && $diff['link_url'] === null
            && $diff['hashtags'] === null
            && $diff['media'] === null
            && $diff['channels'] === [];

        return $diff;
    }

    /**
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     * @return array<string, mixed>|null
     */
    private function mediaDiff(array $old, array $new): ?array
    {
        $oldIds = array_map(fn ($asset) => (int) $asset['asset_id'], $old);
        $newIds = array_map(fn ($asset) => (int) $asset['asset_id'], $new);

        $added = count(array_diff($newIds, $oldIds));
        $removed = count(array_diff($oldIds, $newIds));
        $kept = array_values(array_intersect($oldIds, $newIds));
        $reordered = $kept !== array_values(array_intersect($newIds, $oldIds));

        if ($added === 0 && $removed === 0 && ! $reordered) {
            return null;
        }

        return ['added' => $added, 'removed' => $removed, 'reordered' => $reordered];
    }

    /**
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     * @return list<array<string, mixed>>
     */
    private function channelDiff(array $old, array $new): array
    {
        $index = fn (array $targets) => collect($targets)->keyBy(fn ($target) => (int) $target['social_account_id']);
        $before = $index($old);
        $after = $index($new);
        $fields = ['caption_override', 'hashtags_override', 'first_comment', 'link_url', 'format'];
        $changes = [];

        foreach ($after as $id => $target) {
            $previous = $before->get($id);

            if ($previous === null) {
                $changes[] = ['platform' => $target['platform'], 'handle' => $target['handle'], 'fields' => ['added']];

                continue;
            }

            $changed = array_values(array_filter($fields, fn ($field) => ($previous[$field] ?? null) != ($target[$field] ?? null)));

            if ($changed !== []) {
                $changes[] = ['platform' => $target['platform'], 'handle' => $target['handle'], 'fields' => $changed];
            }
        }

        foreach ($before as $id => $target) {
            if (! $after->has($id)) {
                $changes[] = ['platform' => $target['platform'], 'handle' => $target['handle'], 'fields' => ['removed']];
            }
        }

        return $changes;
    }
}
