<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\CreatePostsFromGridTilesAction;
use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\GridPlanStatus;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Notifications\GridOrderAtRisk;
use App\Services\Grids\GridDriftService;
use App\Services\Grids\GridPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.11 — keeping the grid true after scheduling: outside posts in their real slot, drift
 * from the approved look, "Grid order at risk" with "Hold the rest", and the calendar's grid
 * filter.
 */
class GridKeepTrueTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    }

    public function test_an_outside_post_lands_in_its_real_slot_and_can_be_added_to_the_plan(): void
    {
        $plan = $this->datedPlan(3);
        // Tile 2 goes out on 1 Nov 09:00, tile 1 on 2 Nov 09:00 — this lands between them.
        $outside = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled]);
        $outside->targets()->create(['social_account_id' => $this->instagram->id, 'scheduled_local_date' => '2026-11-01', 'scheduled_local_time' => '18:00', 'scheduled_at_utc' => '2026-11-01 18:00:00']);
        $outside->assets()->attach($this->image()->id, ['sort_order' => 0]);

        $layout = (new GridPlanService)->layout($plan);
        $this->assertSame(['tile', 'outside', 'tile', 'tile'], array_column($layout->slots, 'kind'));
        $this->assertSame(1, $layout->outside()[0]['slot']);

        $this->actingAs($this->owner())->post(route('grids.outside-posts.store', [$plan, $outside]))->assertRedirect();

        $tiles = $plan->tiles()->get();
        $this->assertCount(4, $tiles);
        $this->assertSame($outside->id, $tiles[1]->post_id);
        $this->assertSame([], (new GridPlanService)->layout(GridPlan::query()->findOrFail($plan->id))->outside());
    }

    public function test_drift_is_detected_on_media_crop_and_format_changes(): void
    {
        $plan = $this->approvedWithPosts(1);
        $tile = $plan->tiles()->with('assets')->firstOrFail();
        $post = $tile->post()->firstOrFail();
        $drift = new GridDriftService;

        $this->assertSame([], $drift->changes($plan, $tile, $post->fresh() ?? $post));

        $assetId = $post->assets()->firstOrFail()->id;
        $post->assets()->updateExistingPivot($assetId, ['crop_x' => 0.1]);
        $this->assertSame(['An image was reframed.'], $drift->changes($plan, $tile, Post::query()->findOrFail($post->id)));

        $post->assets()->updateExistingPivot($assetId, ['crop_x' => 0.5]);
        $post->update(['media_format' => ['instagram' => MediaRatio::Square->value]]);
        $this->assertSame(['The format changed to 1:1.'], $drift->changes($plan, $tile, Post::query()->findOrFail($post->id)));

        $post->assets()->sync([$this->image()->id => ['sort_order' => 0]]);
        $this->assertContains('The images or their order changed.', $drift->changes($plan, $tile, Post::query()->findOrFail($post->id)));

        // The post's own checklist warns, softly.
        $item = collect((new EvaluatePostChecklistAction)(Post::query()->findOrFail($post->id))['items'])->firstWhere('key', 'grid_match');
        $this->assertFalse($item['passed']);
        $this->assertTrue($item['soft']);
    }

    public function test_a_reorder_after_approval_shows_as_layout_drift_and_the_tile_and_post_can_be_reconciled(): void
    {
        $plan = $this->approvedWithPosts(2);
        $plan->update(['approved_layout_hash' => (new GridPlanService)->layoutHash($plan)]);
        $hash = $plan->approved_layout_hash;

        GridTile::query()->where('grid_plan_id', $plan->id)->update(['position' => DB::raw('position + 100')]);
        $ids = GridTile::query()->where('grid_plan_id', $plan->id)->orderByDesc('position')->pluck('id')->all();
        foreach ($ids as $i => $id) {
            GridTile::query()->whereKey($id)->update(['position' => $i + 1]);
        }
        $this->assertNotSame($hash, (new GridPlanService)->layoutHash(GridPlan::query()->findOrFail($plan->id)));

        // "Update the tile from the post" reopens the grid to draft.
        $tile = $plan->tiles()->firstOrFail();
        $post = $tile->post()->firstOrFail();
        $post->update(['media_format' => ['instagram' => '1:1']]);
        $this->actingAs($this->owner())->post(route('grids.tiles.sync-from-post', [$plan, $tile]))->assertRedirect();
        $this->assertSame(MediaRatio::Square, $tile->fresh()?->format);
        $this->assertSame(GridPlanStatus::Draft, $plan->fresh()?->status);

        // "Put the post back" restores the tile's look on the post.
        $tile->refresh()->update(['format' => MediaRatio::Classic]);
        $this->actingAs($this->owner())->post(route('grids.tiles.restore-post', [$plan, $tile]))->assertRedirect();
        $this->assertSame(MediaRatio::Classic, Post::query()->findOrFail($post->id)->mediaRatioFor(Platform::Instagram));
    }

    public function test_a_failed_grid_post_with_later_tiles_scheduled_puts_the_order_at_risk(): void
    {
        $plan = $this->approvedWithPosts(3);
        $posts = $this->postsByPosition($plan);
        foreach ($posts as $post) {
            $post->update(['status' => PostStatus::Scheduled]);
        }

        // Tile 3 goes out first — it fails while tiles 2 and 1 are still scheduled.
        $posts[3]->update(['status' => PostStatus::Failed]);

        $plan->refresh();
        $this->assertNotNull($plan->order_risk_notified_at);
        Notification::assertSentToTimes($this->owner(), GridOrderAtRisk::class, 1);

        $home = app(GetNeedsAttentionItemsAction::class)($this->owner());
        $this->assertSame(1, $home['gridRisks']['total']);
        $this->assertSame($plan->id, $home['gridRisks']['items'][0]['grid_plan_id']);
        $this->assertSame(2, $home['gridRisks']['items'][0]['later_count']);

        // The client never sees it.
        $this->assertSame(0, app(GetNeedsAttentionItemsAction::class)($this->reviewer)['gridRisks']['total']);
    }

    public function test_the_last_tile_failing_is_not_an_order_risk(): void
    {
        $plan = $this->approvedWithPosts(3);
        $posts = $this->postsByPosition($plan);
        $posts[3]->update(['status' => PostStatus::Published]);
        $posts[2]->update(['status' => PostStatus::Published]);
        $posts[1]->update(['status' => PostStatus::Failed]);

        $this->assertNull($plan->fresh()?->order_risk_notified_at);
        Notification::assertNothingSent();
    }

    public function test_hold_the_rest_moves_only_the_later_tiles_back_to_approved(): void
    {
        $plan = $this->approvedWithPosts(4);
        $posts = $this->postsByPosition($plan);
        foreach ($posts as $post) {
            $post->update(['status' => PostStatus::Scheduled]);
        }
        $posts[4]->update(['status' => PostStatus::Published]);
        $posts[3]->update(['status' => PostStatus::Failed]);

        $this->actingAs($this->designer())->post(route('grids.hold', $plan))->assertRedirect();

        $this->assertSame(PostStatus::Published, $posts[4]->fresh()?->status);
        $this->assertSame(PostStatus::Failed, $posts[3]->fresh()?->status);
        $this->assertSame(PostStatus::Approved, $posts[2]->fresh()?->status);
        $this->assertSame(PostStatus::Approved, $posts[1]->fresh()?->status);
        $this->assertNull($plan->fresh()?->order_risk_notified_at, 'Holding the rest clears the risk.');

        $this->actingAs($this->designer())->post(route('grids.hold', $plan))->assertSessionHasErrors('grid');
        $this->actingAs($this->viewer())->post(route('grids.hold', $plan))->assertForbidden();
    }

    public function test_the_calendar_marks_grid_posts_and_filters_to_one_grid(): void
    {
        $plan = $this->approvedWithPosts(2);
        $other = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        $other->targets()->create(['social_account_id' => $this->instagram->id, 'scheduled_local_date' => '2026-11-01', 'scheduled_local_time' => '12:00', 'scheduled_at_utc' => '2026-11-01 12:00:00']);

        $this->actingAs($this->owner())->get(route('calendar', ['from' => '2026-10-26', 'to' => '2026-11-08']))
            ->assertInertia(fn ($page) => $page
                ->has('occurrences', 3)
                ->has('filterOptions.grids', 1));

        $this->actingAs($this->owner())->get(route('calendar', ['from' => '2026-10-26', 'to' => '2026-11-08', 'grid_id' => $plan->id]))
            ->assertInertia(fn ($page) => $page
                ->has('occurrences', 2)
                ->where('occurrences.0.grid_plan_id', $plan->id)
                ->where('filters.grid_id', $plan->id));
    }

    /**
     * Tiles dated one a day from 31 October, the last position first.
     */
    private function datedPlan(int $count, GridPlanStatus $status = GridPlanStatus::Draft): GridPlan
    {
        $plan = $this->planWithTiles($count, $status);

        foreach ($plan->tiles()->get() as $tile) {
            $tile->update(['planned_local_date' => CarbonImmutable::parse('2026-10-31')->addDays($count - $tile->position)->toDateString(), 'planned_local_time' => '09:00']);
        }

        return GridPlan::query()->findOrFail($plan->id);
    }

    private function approvedWithPosts(int $count): GridPlan
    {
        $plan = $this->datedPlan($count, GridPlanStatus::Approved);
        $plan->tiles()->update(['caption_idea' => 'Caption']);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());

        return GridPlan::query()->findOrFail($plan->id);
    }

    /**
     * @return array<int, Post>
     */
    private function postsByPosition(GridPlan $plan): array
    {
        return $plan->tiles()->with('post')->get()->mapWithKeys(fn (GridTile $tile) => [$tile->position => $tile->post])->all();
    }
}
