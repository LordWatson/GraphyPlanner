<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\EvaluateGridChecklistAction;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Grids\GridPlanService;
use App\Services\Publishing\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.2 — every grid checklist item passes and fails on its own, and the layout hash is
 * stable but tracks the look.
 */
class GridChecklistTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
    }

    public function test_a_clean_three_tile_grid_passes_every_item(): void
    {
        $result = $this->evaluate($this->planWithTiles(3));

        $this->assertTrue($result['passed']);
        $this->assertSame([], array_values(array_filter($result['items'], fn (array $item) => ! $item['passed'])));
    }

    public function test_tiles_fails_on_an_empty_grid(): void
    {
        $this->assertFailed($this->evaluate($this->makePlan()), 'tiles');
    }

    public function test_media_fails_for_a_placeholder_a_short_carousel_and_a_reel_without_video(): void
    {
        $placeholder = $this->planWithTiles(2);
        $tile = $this->addTile($placeholder, GridTileKind::Placeholder);
        $item = $this->assertFailed($this->evaluate($placeholder->refresh()), 'media');
        $this->assertSame([$tile->id], $item['tile_ids']);
        $this->assertStringContainsString('still a placeholder', (string) $item['detail']);

        $carousel = $this->makePlan();
        $this->addTile($carousel, GridTileKind::Carousel, [$this->image()]);
        $this->assertStringContainsString('Carousels take 2–10', (string) $this->assertFailed($this->evaluate($carousel->refresh()), 'media')['detail']);

        $reel = $this->makePlan();
        $this->addTile($reel, GridTileKind::Reel, [$this->image(1080, 1920)]);
        $this->assertStringContainsString('needs a video', (string) $this->assertFailed($this->evaluate($reel->refresh()), 'media')['detail']);
    }

    public function test_carousel_bounds_come_from_the_registry(): void
    {
        config()->set('platforms.instagram.formats.carousel.max_items', 3);
        app()->forgetInstance(PlatformCapabilities::class);

        $plan = $this->makePlan();
        $this->addTile($plan, GridTileKind::Carousel, [$this->image(), $this->image(), $this->image(), $this->image()]);

        $this->assertStringContainsString('Carousels take 2–3', (string) $this->assertFailed($this->evaluate($plan->refresh()), 'media')['detail']);
    }

    public function test_formats_fails_for_a_format_instagram_does_not_offer_for_that_tile(): void
    {
        $plan = $this->planWithTiles(1);
        $this->setFormat($plan->tiles->first(), MediaRatio::Vertical);

        $this->assertFailed($this->evaluate($plan->refresh()), 'formats');
    }

    public function test_split_alignment_fails_when_pinned_posts_push_a_split_off_its_row(): void
    {
        $plan = $this->makePlan();
        $group = (string) Str::uuid();

        foreach (range(1, 3) as $i) {
            $this->addTile($plan, attributes: ['split_group_id' => $group]);
        }

        $this->assertTrue($this->item($this->evaluate($plan->refresh()), 'split_alignment')['passed']);

        $plan->update(['pinned_count' => 1]);
        $item = $this->assertFailed($this->evaluate($plan->refresh()), 'split_alignment');
        $this->assertStringContainsString("This split won't line up. Add or remove one tile above it.", (string) $item['detail']);
        $this->assertCount(3, $item['tile_ids']);
    }

    public function test_account_fails_when_the_instagram_account_is_disconnected(): void
    {
        $plan = $this->planWithTiles(1);
        $this->disconnect($this->instagram);

        $this->assertFailed($this->evaluate($plan->refresh()), 'account');
    }

    public function test_grid_crop_warns_for_a_4_5_tile_but_never_blocks(): void
    {
        $plan = $this->planWithTiles(2);
        $tile = $plan->tiles->first();
        $this->setFormat($tile, MediaRatio::Portrait);

        $result = $this->evaluate($plan->refresh());
        $item = $this->assertFailed($result, 'grid_crop');

        $this->assertTrue($item['soft']);
        $this->assertSame([$tile->id], $item['tile_ids']);
        $this->assertStringContainsString('loses 6%', (string) $item['detail']);
        $this->assertTrue($result['passed']);
    }

    public function test_dates_warns_for_a_past_date_and_for_tiles_out_of_publish_order(): void
    {
        $past = $this->planWithTiles(1);
        $past->tiles->first()->update(['planned_local_date' => now()->subDay()->toDateString(), 'planned_local_time' => '09:00']);
        $this->assertStringContainsString('in the past', (string) $this->assertFailed($this->evaluate($past->refresh()), 'dates')['detail']);

        // Tile 1 (top-left) must go out after tile 2; here it's the other way round.
        $order = $this->planWithTiles(2);
        [$first, $second] = [$order->tiles[0], $order->tiles[1]];
        $first->update(['planned_local_date' => now()->addDays(2)->toDateString(), 'planned_local_time' => '09:00']);
        $second->update(['planned_local_date' => now()->addDays(3)->toDateString(), 'planned_local_time' => '09:00']);

        $result = $this->evaluate($order->refresh());
        $this->assertStringContainsString('This puts tile 1 before tile 2. The grid will be out of order.', (string) $this->assertFailed($result, 'dates')['detail']);
        $this->assertTrue($result['passed']);
    }

    public function test_outside_posts_warns_when_another_feed_post_goes_out_between_grid_dates(): void
    {
        $plan = $this->planWithTiles(2);
        $plan->tiles[0]->update(['planned_local_date' => now()->addDays(4)->toDateString(), 'planned_local_time' => '09:00']);
        $plan->tiles[1]->update(['planned_local_date' => now()->addDays(2)->toDateString(), 'planned_local_time' => '09:00']);

        $this->assertTrue($this->item($this->evaluate($plan->refresh()), 'outside_posts')['passed']);

        $other = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled]);
        $other->targets()->create([
            'social_account_id' => $this->instagram->id,
            'scheduled_local_date' => now()->addDays(3)->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDays(3),
        ]);

        $this->assertStringContainsString('shift the grid by one', (string) $this->assertFailed($this->evaluate($plan->refresh()), 'outside_posts')['detail']);
    }

    public function test_a_story_or_another_account_never_counts_as_an_outside_post(): void
    {
        $plan = $this->planWithTiles(2);
        $plan->tiles[0]->update(['planned_local_date' => now()->addDays(4)->toDateString(), 'planned_local_time' => '09:00']);
        $plan->tiles[1]->update(['planned_local_date' => now()->addDays(2)->toDateString(), 'planned_local_time' => '09:00']);

        $story = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled]);
        $story->targets()->create(['social_account_id' => $this->instagram->id, 'format' => 'story', 'scheduled_at_utc' => now()->addDays(3)]);

        $tiktok = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok]);
        $elsewhere = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled]);
        $elsewhere->targets()->create(['social_account_id' => $tiktok->id, 'scheduled_at_utc' => now()->addDays(3)]);

        $this->assertTrue($this->item($this->evaluate($plan->refresh()), 'outside_posts')['passed']);
    }

    public function test_layout_hash_is_stable_across_reloads_and_changes_when_one_crop_moves(): void
    {
        $plan = $this->planWithTiles(3);
        $grids = new GridPlanService;

        $hash = $grids->layoutHash($plan);
        $this->assertSame($hash, $grids->layoutHash(GridPlan::query()->findOrFail($plan->id)));

        // Notes and dates aren't part of the look.
        $plan->tiles[0]->update(['team_note' => 'Swap if the shoot runs late', 'planned_local_date' => now()->addDay()->toDateString()]);
        $this->assertSame($hash, $grids->layoutHash(GridPlan::query()->findOrFail($plan->id)));

        $tile = $plan->tiles[1];
        $tile->assets()->updateExistingPivot($tile->assets->first()->id, ['crop_x' => 0.3]);
        $this->assertNotSame($hash, $grids->layoutHash(GridPlan::query()->findOrFail($plan->id)));
    }

    /**
     * @return array{items: list<array<string, mixed>>, passed: bool}
     */
    private function evaluate(GridPlan $plan): array
    {
        return (new EvaluateGridChecklistAction)($plan);
    }

    /**
     * @param  array{items: list<array<string, mixed>>, passed: bool}  $result
     * @return array<string, mixed>
     */
    private function item(array $result, string $key): array
    {
        $item = collect($result['items'])->firstWhere('key', $key);
        $this->assertNotNull($item, "No checklist item {$key}");

        return $item;
    }

    /**
     * Assert `$key` failed and every other hard item is unaffected by it.
     *
     * @param  array{items: list<array<string, mixed>>, passed: bool}  $result
     * @return array<string, mixed>
     */
    private function assertFailed(array $result, string $key): array
    {
        $item = $this->item($result, $key);
        $this->assertFalse($item['passed'], "{$key} should fail");

        $others = array_filter($result['items'], fn (array $other) => $other['key'] !== $key && ! $other['passed'] && ! $other['soft']
            && ! ($key === 'media' && $other['key'] === 'tiles'));
        $this->assertSame([], array_values(array_map(fn (array $other) => $other['key'], $others)), "Only {$key} should fail");

        return $item;
    }
}
