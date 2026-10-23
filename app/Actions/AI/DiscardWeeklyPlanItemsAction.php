<?php

namespace App\Actions\AI;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.3 — discard weekly-plan items: delete the chosen, un-accepted plan items. Scoped to the
 * brand's own items; an already-accepted item (one that became a post) is left alone.
 */
class DiscardWeeklyPlanItemsAction
{
    /**
     * @param  list<int>  $ids
     * @return int the number of plan items discarded
     */
    public function __invoke(Client $brand, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = $brand->aiPostIdeas()
            ->whereIn('id', $ids)
            ->whereNotNull('planned_for')
            ->whereNull('post_id')
            ->delete();

        Log::info('Weekly plan items discarded', ['org_id' => $brand->org_id, 'client_id' => $brand->id, 'count' => $deleted]);

        return $deleted;
    }
}
