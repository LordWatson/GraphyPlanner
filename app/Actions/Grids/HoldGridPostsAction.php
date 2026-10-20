<?php

namespace App\Actions\Grids;

use App\Actions\Posts\BulkTransitionPostsAction;
use App\Enums\PostStatus;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\User;
use App\Services\Grids\GridOrderRiskService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HoldGridPostsAction
{
    public function __construct(
        private readonly BulkTransitionPostsAction $bulk,
        private readonly GridOrderRiskService $risk = new GridOrderRiskService,
    ) {}

    /**
     * Step 18.11 — "Hold the rest": move the later tiles' scheduled posts back to `approved` through
     * the normal transition (channels the vendor holds are recalled), so nothing goes out of order
     * while the team fixes the broken tile. Only a person runs this; only later tiles are touched.
     *
     * @return array{done: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function __invoke(GridPlan $plan, User $user): array
    {
        $risk = $this->risk->atRisk($plan);

        if ($risk === null) {
            throw ValidationException::withMessages(['grid' => __('The grid is in order. Nothing to hold.')]);
        }

        $posts = Post::query()->whereKey($risk['later_post_ids'])->get();
        $result = ($this->bulk)($posts, PostStatus::Approved, $user);

        $this->risk->check($plan->refresh());

        Log::info('Grid posts held', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'held' => count($result['done']),
            'skipped' => count($result['skipped']),
        ]);

        return $result;
    }
}
