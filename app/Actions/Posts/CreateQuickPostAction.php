<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step B-7.2 — assembles the draft behind the phone-first quick post: one photo/video, a caption
 * and the chosen accounts, each target pre-timed (or left open for "Post now", which
 * {@see QuickSchedulePostAction} retimes). Create + attach run in one transaction so a half-made
 * post never survives a failure. Scheduling is a separate hop (the controller calls
 * {@see QuickSchedulePostAction}), exactly as the editor creates then transitions.
 *
 * It composes the shared {@see CreatePostAction} rather than re-implementing post creation, so the
 * dual-timezone target maths and logging stay in one place.
 */
class CreateQuickPostAction
{
    public function __construct(private readonly CreatePostAction $createPost) {}

    /**
     * @param  list<int>  $accountIds
     */
    public function __invoke(
        Client $brand,
        int $assetId,
        string $caption,
        array $accountIds,
        ?string $localDate,
        ?string $localTime,
    ): Post {
        return DB::transaction(function () use ($brand, $assetId, $caption, $accountIds, $localDate, $localTime) {
            $targets = array_map(fn (int $accountId) => [
                'social_account_id' => $accountId,
                'scheduled_local_date' => $localDate,
                'scheduled_local_time' => $localTime,
            ], $accountIds);

            $post = ($this->createPost)($brand, [
                'status' => PostStatus::Draft->value,
                'master_caption' => $caption,
                'targets' => $targets,
            ]);

            // The single piece of media the quick flow captured, as the first (and only) slide.
            $post->assets()->attach($assetId, ['sort_order' => 0]);

            Log::info('Quick post created', [
                'client_id' => $brand->id,
                'post_id' => $post->id,
                'asset_id' => $assetId,
                'target_count' => count($accountIds),
            ]);

            return $post;
        });
    }
}
