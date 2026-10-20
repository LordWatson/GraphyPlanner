<?php

namespace App\Actions\Grids;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\AssetType;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use App\Services\Grids\GridPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreatePostsFromGridTilesAction
{
    public function __construct(
        private readonly CreatePostAction $createPost,
        private readonly GridPlanChanges $changes = new GridPlanChanges,
        private readonly EvaluatePostChecklistAction $checklist = new EvaluatePostChecklistAction,
    ) {}

    /**
     * Step 18.9 — turn an approved grid's tiles (all, or a selection) into draft posts. The grid
     * approved the look; each post still goes through its own workflow for the words, so it starts
     * in `draft` with the client's approval mode.
     *
     * Each post gets the plan's client and campaign, one Instagram target on the plan's account
     * (local date/time from the tile, UTC from the account's timezone), the caption idea as its
     * master caption, the tile's media in carousel order with framing and alt text copied, the
     * tile's format, the target format (feed / carousel / reel) and a Reel's cover.
     *
     * Idempotent: a tile that already has a post is left alone. A tile that can't become a post
     * (a placeholder, missing media) is reported with the reason and the rest are still created,
     * all in one transaction.
     *
     * @param  list<int>|null  $tileIds  null = every tile
     * @return array{created: array<int, int>, skipped: list<array{tile_id: int, position: int, reason: string}>}
     */
    public function __invoke(GridPlan $plan, User $user, ?array $tileIds = null): array
    {
        if ($plan->status !== GridPlanStatus::Approved) {
            throw ValidationException::withMessages(['grid' => __('Approve the grid before turning its tiles into posts.')]);
        }

        $result = DB::transaction(function () use ($plan, $user, $tileIds) {
            $locked = $this->changes->lock($plan);
            $locked->load(['client', 'socialAccount', 'tiles.assets', 'tiles.coverAsset']);

            $created = [];
            $skipped = [];

            foreach ($locked->tiles as $tile) {
                if ($tileIds !== null && ! in_array($tile->id, $tileIds, true)) {
                    continue;
                }

                if ($tile->post_id !== null) {
                    continue;
                }

                $reason = $this->problem($tile);

                if ($reason !== null) {
                    $skipped[] = ['tile_id' => $tile->id, 'position' => $tile->position, 'reason' => $reason];

                    continue;
                }

                $post = $this->createPost($locked, $tile, $user);
                $tile->update(['post_id' => $post->id]);
                $created[$tile->id] = $post->id;
            }

            Log::info('Posts created from grid tiles', [
                'grid_plan_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $user->id,
                'created' => count($created),
                'skipped' => count($skipped),
            ]);

            return ['created' => $created, 'skipped' => $skipped];
        });

        return $result;
    }

    private function problem(GridTile $tile): ?string
    {
        $carousel = Platform::Instagram->spec()->format('carousel');
        $count = $tile->assets->count();

        return match ($tile->kind) {
            GridTileKind::Placeholder => 'It’s still a placeholder. Add its media first.',
            GridTileKind::Image => $count === 0 ? 'It has no image.' : null,
            GridTileKind::Carousel => $count < $carousel->minItems || $count > $carousel->maxItems
                ? "A carousel needs {$carousel->minItems}–{$carousel->maxItems} items."
                : null,
            GridTileKind::Reel => GridPlanService::hasVideo($tile) ? null : 'A Reel needs a video.',
        };
    }

    private function createPost(GridPlan $plan, GridTile $tile, User $user): Post
    {
        $post = ($this->createPost)($plan->client, [
            'campaign_id' => $plan->campaign_id,
            'created_by' => $user->id,
            'status' => PostStatus::Draft,
            'master_caption' => $tile->caption_idea,
            'media_format' => $tile->kind === GridTileKind::Reel ? null : [Platform::Instagram->value => $tile->format->value],
            'cover_asset_id' => $tile->kind === GridTileKind::Reel ? $tile->cover_asset_id : null,
            'targets' => [[
                'social_account_id' => $plan->social_account_id,
                'scheduled_local_date' => $tile->planned_local_date?->toDateString(),
                'scheduled_local_time' => $tile->planned_local_time,
            ]],
        ]);

        // A Reel publishes its one video; images keep their carousel order.
        $media = $tile->kind === GridTileKind::Reel
            ? $tile->assets->filter(fn (Asset $asset) => $asset->type === AssetType::Video)->take(1)
            : $tile->assets;

        $post->assets()->sync($media->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => GridPlanService::pivotRow($asset, $order)])->all());

        $post->targets()->update(['format' => $tile->kind->targetFormat()]);

        $post->load(['targets.socialAccount', 'assets']);
        $post->update(['checklist_snapshot' => ($this->checklist)($post)]);

        return $post;
    }
}
