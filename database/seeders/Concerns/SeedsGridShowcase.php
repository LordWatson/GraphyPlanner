<?php

namespace Database\Seeders\Concerns;

use App\Enums\ApprovalMode;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\CampaignColour;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Grids\GridPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Phase 18 (Step 18.13) — grid plans for the preview fixture and the demo clients. Idempotent:
 * every row is keyed (plan by name, tile by position, asset by URL, post by caption), so re-running
 * never duplicates.
 */
trait SeedsGridShowcase
{
    /**
     * An approved nine-tile grid: a 1×3 split banner on the top row, a carousel, a Reel and
     * single images, with six tiles already turned into posts at different stages.
     */
    protected function seedApprovedGrid(Client $client, User $owner, SocialAccount $instagram, string $label): GridPlan
    {
        $plan = GridPlan::query()->withoutGlobalScopes()->updateOrCreate(
            ['client_id' => $client->id, 'name' => "{$label} launch grid"],
            [
                'org_id' => $client->org_id,
                'social_account_id' => $instagram->id,
                'status' => GridPlanStatus::Approved,
                'review_message' => 'The new season, top row first: the banner across row one, then the story underneath.',
                'pinned_count' => 0,
                'include_live_feed' => true,
                'created_by' => $owner->id,
            ],
        );

        $split = $plan->tiles()->whereNotNull('split_group_id')->value('split_group_id') ?? (string) Str::uuid();
        $image = fn (string $key, int $w = 1080, int $h = 1440) => $this->gridAsset($client, $owner, "https://picsum.photos/seed/{$client->id}-grid-{$key}/{$w}/{$h}", $w, $h, AssetType::Image);

        $tiles = [
            1 => [GridTileKind::Image, [$image('split-1')], ['split_group_id' => $split, 'caption_idea' => 'The new season starts here.']],
            2 => [GridTileKind::Image, [$image('split-2')], ['split_group_id' => $split]],
            3 => [GridTileKind::Image, [$image('split-3')], ['split_group_id' => $split]],
            4 => [GridTileKind::Carousel, [$image('carousel-1', 1080, 1350), $image('carousel-2', 1080, 1350), $image('carousel-3', 1080, 1350)], ['format' => MediaRatio::Portrait, 'caption_idea' => 'Three ways to wear it.']],
            5 => [GridTileKind::Reel, [$this->gridAsset($client, $owner, "https://example.com/{$client->id}/grid-reel.mp4", 1080, 1920, AssetType::Video)], ['caption_idea' => 'Behind the scenes at the shoot.']],
            6 => [GridTileKind::Image, [$image('single-6')], ['caption_idea' => 'Meet the maker.']],
            7 => [GridTileKind::Image, [$image('single-7', 1080, 1080)], ['format' => MediaRatio::Square, 'caption_idea' => 'Small batch, made by hand.']],
            8 => [GridTileKind::Image, [$image('single-8')], ['caption_idea' => 'Coming soon.']],
            9 => [GridTileKind::Image, [$image('single-9')], ['caption_idea' => 'Something new is on its way.']],
        ];

        $statuses = [4 => PostStatus::WaitingClient, 5 => PostStatus::Approved, 6 => PostStatus::Approved, 7 => PostStatus::Scheduled, 8 => PostStatus::Scheduled, 9 => PostStatus::Scheduled];

        foreach ($tiles as $position => [$kind, $assets, $extra]) {
            // Tile 9 goes out first: one a day from tomorrow, 09:00 on the account's clock.
            $date = now($instagram->timezone)->addDays(10 - $position)->toDateString();

            $tile = $this->gridTile($plan, $position, $kind, $assets, [
                'planned_local_date' => $date,
                'planned_local_time' => '09:00',
                ...$extra,
            ]);

            if (isset($statuses[$position])) {
                $this->gridPost($client, $owner, $instagram, $tile, $statuses[$position], $date);
            }
        }

        $plan->unsetRelation('tiles');
        $grids = new GridPlanService;

        $plan->forceFill([
            'approved_at' => $plan->approved_at ?? now()->subDay(),
            'approved_by' => $owner->id,
            'approved_layout_hash' => $grids->layoutHash($plan),
        ])->save();

        return $plan;
    }

    /**
     * A draft grid with a few tiles and a placeholder, for the planner's everyday state.
     */
    protected function seedDraftGrid(Client $client, User $owner, SocialAccount $instagram): GridPlan
    {
        $plan = GridPlan::query()->withoutGlobalScopes()->updateOrCreate(
            ['client_id' => $client->id, 'name' => 'Next month’s grid'],
            [
                'org_id' => $client->org_id,
                'social_account_id' => $instagram->id,
                'status' => GridPlanStatus::Draft,
                'pinned_count' => 1,
                'include_live_feed' => true,
                'created_by' => $owner->id,
            ],
        );

        $image = fn (string $key) => $this->gridAsset($client, $owner, "https://picsum.photos/seed/{$client->id}-draft-{$key}/1080/1440", 1080, 1440, AssetType::Image);

        $this->gridTile($plan, 1, GridTileKind::Placeholder, [], ['placeholder_colour' => CampaignColour::Lilac, 'team_note' => 'Product shot from the next shoot.']);
        $this->gridTile($plan, 2, GridTileKind::Image, [$image('a')], ['caption_idea' => 'A quiet Sunday.']);
        $this->gridTile($plan, 3, GridTileKind::Image, [$image('b')], []);
        $this->gridTile($plan, 4, GridTileKind::Image, [$image('c')], ['format' => MediaRatio::Portrait]);

        return $plan;
    }

    private function gridAsset(Client $client, User $owner, string $url, int $width, int $height, AssetType $type): Asset
    {
        return Asset::query()->withoutGlobalScopes()->updateOrCreate(
            ['client_id' => $client->id, 'url' => $url],
            [
                'org_id' => $client->org_id,
                'source' => AssetSource::Url,
                'type' => $type,
                'uploaded_by' => $owner->id,
                'width' => $width,
                'height' => $height,
                'duration_ms' => $type === AssetType::Video ? 18000 : null,
                'mime_type' => $type === AssetType::Video ? 'video/mp4' : 'image/jpeg',
                'rights' => 'Stock placeholder — demo use only.',
            ],
        );
    }

    /**
     * @param  list<Asset>  $assets
     * @param  array<string, mixed>  $attributes
     */
    private function gridTile(GridPlan $plan, int $position, GridTileKind $kind, array $assets, array $attributes): GridTile
    {
        /** @var GridTile $tile */
        $tile = GridTile::query()->updateOrCreate(
            ['grid_plan_id' => $plan->id, 'position' => $position],
            ['kind' => $kind, 'format' => $attributes['format'] ?? $kind->defaultFormat(), ...array_diff_key($attributes, ['format' => true])],
        );

        $tile->assets()->sync(collect($assets)->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => ['sort_order' => $order]])->all());

        return $tile;
    }

    private function gridPost(Client $client, User $owner, SocialAccount $instagram, GridTile $tile, PostStatus $status, string $date): void
    {
        $post = Post::query()->withoutGlobalScopes()->updateOrCreate(
            ['client_id' => $client->id, 'master_caption' => $tile->caption_idea ?? "Grid tile {$tile->position}"],
            [
                'org_id' => $client->org_id,
                'created_by' => $owner->id,
                'status' => $status,
                'approval_mode' => ApprovalMode::ClientRequired,
                'hashtags' => [],
                'media_format' => $tile->kind === GridTileKind::Reel ? null : [Platform::Instagram->value => $tile->format->value],
            ],
        );

        $tile->load('assets');
        $post->assets()->sync($tile->assets->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => GridPlanService::pivotRow($asset, $order)])->all());

        PostTarget::query()->updateOrCreate(
            ['post_id' => $post->id, 'social_account_id' => $instagram->id],
            [
                'scheduled_local_date' => $date,
                'scheduled_local_time' => '09:00',
                'scheduled_at_utc' => CarbonImmutable::parse("{$date} 09:00", $instagram->timezone)->utc(),
                'format' => $tile->kind->targetFormat(),
            ],
        );

        $tile->update(['post_id' => $post->id]);
    }
}
