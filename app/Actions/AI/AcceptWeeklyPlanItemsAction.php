<?php

namespace App\Actions\AI;

use App\Models\Client;
use App\Support\AI\AiPostIdea as AiPostIdeaDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.3 — accept weekly-plan items: turn each chosen plan item into a draft `Idea` post (via
 * {@see RecommendPostsAction::persist()}, which also stamps the item's `post_id` so it leaves the
 * plan). Only the brand's own un-accepted plan items are touched; the whole batch is one
 * transaction so a mid-batch failure leaves no half-accepted plan.
 */
class AcceptWeeklyPlanItemsAction
{
    public function __construct(private readonly RecommendPostsAction $recommendPosts) {}

    /**
     * @param  list<int>  $ids
     * @return list<int> the ids of the draft posts created
     */
    public function __invoke(Client $brand, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $items = $brand->aiPostIdeas()
            ->whereIn('id', $ids)
            ->whereNotNull('planned_for')
            ->whereNull('post_id')
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($brand, $items): array {
            $postIds = [];

            foreach ($items as $item) {
                $postIds[] = $this->recommendPosts->persist($brand, new AiPostIdeaDto(
                    title: $item->title,
                    description: (string) $item->description,
                    platforms: $item->platforms ?? [],
                    suggestedCaption: $item->suggested_caption,
                    suggestedPostAt: $item->suggested_post_at,
                ))->id;
            }

            Log::info('Weekly plan items accepted', ['org_id' => $brand->org_id, 'client_id' => $brand->id, 'count' => count($postIds)]);

            return $postIds;
        });
    }
}
