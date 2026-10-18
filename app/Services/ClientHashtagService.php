<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientHashtag;
use App\Models\Post;
use Illuminate\Support\Carbon;

/**
 * Step I-2.6 — keeps `client_hashtags` in step with posts: each tag a save adds counts one more
 * use (and refreshes `last_used_at`), each tag it removes one fewer. Callers run inside their own
 * transaction (`CreatePostAction`, `UpdatePostAction`), so the vocabulary never drifts from a
 * rolled-back post.
 */
class ClientHashtagService
{
    public const SUGGESTION_LIMIT = 50;

    /**
     * @param  array<array-key, mixed>|null  $before  the post's hashtags before this save (null for a new post)
     */
    public function recordChange(Post $post, ?array $before): void
    {
        $previous = self::keyed($before ?? []);
        $current = self::keyed($post->hashtags ?? []);

        $added = array_diff_key($current, $previous);
        $removed = array_diff_key($previous, $current);

        if ($added === [] && $removed === []) {
            return;
        }

        $existing = ClientHashtag::query()
            ->where('client_id', $post->client_id)
            ->whereIn('tag_key', array_keys($added + $removed))
            ->get()
            ->keyBy('tag_key');

        $now = Carbon::now();

        foreach ($added as $key => $tag) {
            $row = $existing->get($key);

            if ($row === null) {
                ClientHashtag::query()->create([
                    'client_id' => $post->client_id,
                    'tag' => $tag,
                    'tag_key' => $key,
                    'uses' => 1,
                    'last_used_at' => $now,
                ]);

                continue;
            }

            $row->forceFill(['uses' => $row->uses + 1, 'last_used_at' => $now])->save();
        }

        foreach ($removed as $key => $tag) {
            $row = $existing->get($key);

            if ($row !== null && $row->uses > 0) {
                $row->forceFill(['uses' => $row->uses - 1])->save();
            }
        }
    }

    /**
     * A deleted post stops counting towards its tags.
     */
    public function recordRemoval(Post $post): void
    {
        $tags = $post->hashtags ?? [];
        $post->hashtags = [];

        try {
            $this->recordChange($post, $tags);
        } finally {
            $post->hashtags = $tags;
        }
    }

    /**
     * The client's most-used, most-recent tags.
     *
     * @return list<string>
     */
    public function suggestions(Client $client, int $limit = self::SUGGESTION_LIMIT): array
    {
        return array_values(ClientHashtag::query()
            ->where('client_id', $client->id)
            ->where('uses', '>', 0)
            ->orderByDesc('uses')
            ->orderByDesc('last_used_at')
            ->orderBy('tag_key')
            ->limit($limit)
            ->pluck('tag')
            ->map(fn ($tag): string => (string) $tag)
            ->all());
    }

    /**
     * @param  array<array-key, mixed>  $tags
     * @return array<string, string> tag_key => tag
     */
    private static function keyed(array $tags): array
    {
        $keyed = [];

        foreach ($tags as $tag) {
            $tag = ltrim(trim((string) $tag), '#');

            if ($tag !== '') {
                $keyed[mb_strtolower($tag)] ??= $tag;
            }
        }

        return $keyed;
    }
}
