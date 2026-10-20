<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\CreatePostsFromGridTilesAction;
use App\Actions\Grids\EvaluateGridChecklistAction;
use App\Actions\Grids\PlanGridScheduleAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\ApprovalMode;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\SendScheduledPostToVendorJob;
use App\Mail\ClientReviewRequestedMail;
use App\Mail\GridPostsReadyMail;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Steps 18.9 and 18.10 — turning an approved grid's tiles into posts, moving their captions
 * through review together, dating the sequence in publish order, and scheduling approved posts.
 */
class GridProductionTest extends TestCase
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

    public function test_n_tiles_become_n_draft_posts_with_media_order_framing_format_cover_and_time(): void
    {
        $plan = $this->makePlan(['status' => GridPlanStatus::Approved]);
        [$a, $b, $c] = [$this->image(), $this->image(), $this->image()];
        $carousel = $this->addTile($plan, GridTileKind::Carousel, [$a, $b], [
            'format' => MediaRatio::Portrait,
            'caption_idea' => 'Two looks',
            'planned_local_date' => '2026-11-02',
            'planned_local_time' => '09:30',
        ]);
        $carousel->assets()->updateExistingPivot($b->id, ['crop_x' => 0.2, 'crop_zoom' => 1.5, 'alt_text' => 'Close-up']);
        $video = $this->video();
        $reel = $this->addTile($plan, GridTileKind::Reel, [$video], ['cover_asset_id' => $c->id]);
        $single = $this->addTile($plan, GridTileKind::Image, [$this->image()]);

        $this->actingAs($this->designer())->post(route('grids.posts.store', $plan))->assertRedirect(route('grids.edit', $plan));

        $this->assertSame(3, Post::query()->count());

        $post = $carousel->fresh()?->post;
        $this->assertNotNull($post);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Two looks', $post->master_caption);
        $this->assertSame(ApprovalMode::ClientRequired, $post->approval_mode);
        $this->assertSame([$a->id, $b->id], $post->assets()->pluck('assets.id')->all());
        $pivot = $post->assets()->whereKey($b->id)->firstOrFail()->pivot;
        $this->assertEqualsWithDelta(0.2, (float) $pivot->crop_x, 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $pivot->crop_zoom, 0.0001);
        $this->assertSame('Close-up', $pivot->alt_text);
        $this->assertSame(MediaRatio::Portrait, $post->mediaRatioFor(Platform::Instagram));

        $target = $post->targets()->firstOrFail();
        $this->assertSame($this->instagram->id, $target->social_account_id);
        $this->assertSame('carousel', $target->format);
        $this->assertSame('2026-11-02', $target->scheduled_local_date?->toDateString());
        // Europe/London is on GMT in November.
        $this->assertSame('2026-11-02T09:30:00+00:00', $target->scheduled_at_utc?->toIso8601String());

        $reelPost = $reel->fresh()?->post;
        $this->assertSame('reel', $reelPost?->targets()->firstOrFail()->format);
        $this->assertSame($c->id, $reelPost?->cover_asset_id);
        $this->assertSame([$video->id], $reelPost?->assets()->pluck('assets.id')->all());

        $this->assertSame('feed', $single->fresh()?->post?->targets()->firstOrFail()->format);
    }

    public function test_running_it_twice_creates_nothing_new(): void
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);

        $first = app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $second = app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());

        $this->assertCount(3, $first['created']);
        $this->assertSame([], $second['created']);
        $this->assertSame(3, Post::query()->count());
    }

    public function test_a_grid_that_is_not_approved_is_refused(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::InternalReview);

        $this->actingAs($this->owner())->post(route('grids.posts.store', $plan))->assertSessionHasErrors('grid');
        $this->assertSame(0, Post::query()->count());

        $this->actingAs($this->viewer())->post(route('grids.posts.store', $plan))->assertForbidden();
    }

    public function test_an_invalid_tile_in_a_selection_is_reported_and_the_rest_are_created(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        $placeholder = $this->addTile($plan, GridTileKind::Placeholder);
        $ids = $plan->tiles()->pluck('id')->all();

        $result = app(CreatePostsFromGridTilesAction::class)($plan, $this->owner(), $ids);

        $this->assertCount(2, $result['created']);
        $this->assertSame([['tile_id' => $placeholder->id, 'position' => 3, 'reason' => 'It’s still a placeholder. Add its media first.']], $result['skipped']);
        $this->assertNull($placeholder->fresh()?->post_id);

        // Only the selection is turned into posts.
        $fresh = $this->planWithTiles(3, GridPlanStatus::Approved);
        $firstOnly = [$fresh->tiles()->firstOrFail()->id];
        $this->assertCount(1, app(CreatePostsFromGridTilesAction::class)($fresh, $this->owner(), $firstOnly)['created']);
    }

    public function test_the_post_editor_and_the_portal_carry_the_grid_chip(): void
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $tile = $plan->tiles()->where('position', 2)->firstOrFail();
        $post = $tile->post()->firstOrFail();

        $this->actingAs($this->owner())->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page
                ->where('grid.id', $plan->id)
                ->where('grid.name', $plan->name)
                ->where('grid.tile_position', 2)
                ->where('grid.sequence', 2));

        $post->update(['status' => PostStatus::WaitingClient]);
        $this->actingAs($this->reviewer)->get(route('portal.posts.show', $post))
            ->assertInertia(fn ($page) => $page->where('post.grid_name', $plan->name));
    }

    public function test_the_grid_captions_go_to_the_client_as_one_batch(): void
    {
        $this->client->update(['default_approval_mode' => ApprovalMode::ClientRequired]);
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $posts = Post::query()->get();
        $posts->each(fn (Post $post) => $post->update(['master_caption' => 'Words', 'status' => PostStatus::InternalReview]));

        $this->actingAs($this->owner())->post(route('grids.posts.bulk', $plan), ['action' => 'send_to_client'])->assertRedirect();

        $this->assertTrue(Post::query()->get()->every(fn (Post $post) => $post->status === PostStatus::WaitingClient));
        Mail::assertQueued(GridPostsReadyMail::class, 1);
        Mail::assertNotQueued(ClientReviewRequestedMail::class);
    }

    public function test_dates_go_in_publish_order_across_a_dst_change_and_preview_writes_nothing(): void
    {
        $plan = $this->planWithTiles(4, GridPlanStatus::Approved);
        $input = ['start_date' => '2026-10-23', 'time' => '09:00', 'cadence' => 'daily'];

        $response = $this->actingAs($this->owner())->getJson(route('grids.schedule.preview', ['grid' => $plan, ...$input]))->assertOk();

        // Tile 4 goes out first. London leaves BST on Sunday 25 October.
        $this->assertSame([4, 3, 2, 1], array_column($response->json('rows'), 'position'));
        $this->assertSame(['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'], array_column($response->json('rows'), 'date'));
        $this->assertSame(['2026-10-23T08:00:00+00:00', '2026-10-24T08:00:00+00:00', '2026-10-25T09:00:00+00:00', '2026-10-26T09:00:00+00:00'], array_column($response->json('rows'), 'publish_at'));
        $this->assertSame(0, $plan->tiles()->whereNotNull('planned_local_date')->count());

        $this->actingAs($this->owner())->post(route('grids.schedule', $plan), $input)->assertRedirect();
        $this->assertSame(['2026-10-26', '2026-10-25', '2026-10-24', '2026-10-23'], $plan->tiles()->get()->map(fn (GridTile $tile) => $tile->planned_local_date?->toDateString())->all());
        $this->assertSame(GridPlanStatus::Approved, $plan->fresh()?->status, 'Dates never reopen the approved look.');
    }

    public function test_weekday_and_every_n_days_cadences(): void
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);
        $action = app(PlanGridScheduleAction::class);

        // 2026-10-23 is a Friday: Mon/Wed/Fri gives Fri, Mon, Wed.
        $weekdays = $action->preview($plan, ['start_date' => '2026-10-23', 'time' => '18:00', 'cadence' => 'weekdays', 'weekdays' => [1, 3, 5]]);
        $this->assertSame(['2026-10-23', '2026-10-26', '2026-10-28'], array_column($weekdays, 'date'));

        $everyThree = $action->preview($plan, ['start_date' => '2026-10-23', 'time' => '18:00', 'cadence' => 'every_n_days', 'every_n_days' => 3]);
        $this->assertSame(['2026-10-23', '2026-10-26', '2026-10-29'], array_column($everyThree, 'date'));

        $this->expectException(ValidationException::class);
        $action->preview($plan, ['start_date' => '2026-10-23', 'time' => '18:00', 'cadence' => 'weekdays', 'weekdays' => []]);
    }

    public function test_applying_dates_writes_each_posts_instagram_target_and_keeps_other_channels(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $post = $plan->tiles()->where('position', 1)->firstOrFail()->post()->firstOrFail();
        $tiktok = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok, 'timezone' => 'UTC']);
        $post->targets()->create(['social_account_id' => $tiktok->id, 'scheduled_local_date' => '2026-12-01', 'scheduled_local_time' => '12:00', 'scheduled_at_utc' => '2026-12-01 12:00:00']);

        app(PlanGridScheduleAction::class)->apply($plan, $this->owner(), ['start_date' => '2026-11-10', 'time' => '08:15', 'cadence' => 'daily']);

        $targets = $post->targets()->get()->keyBy('social_account_id');
        $this->assertSame('2026-11-11', $targets[$this->instagram->id]->scheduled_local_date?->toDateString());
        $this->assertSame('08:15', substr((string) $targets[$this->instagram->id]->scheduled_local_time, 0, 5));
        $this->assertSame('2026-12-01', $targets[$tiktok->id]->scheduled_local_date?->toDateString());
    }

    public function test_the_min_gap_rule_and_an_out_of_order_calendar_move_warn_without_blocking(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        [$first, $second] = $plan->tiles()->get()->all();
        $second->update(['planned_local_date' => '2026-11-01', 'planned_local_time' => '09:00']);
        $first->update(['planned_local_date' => '2026-11-01', 'planned_local_time' => '09:05']);

        $dates = collect((new EvaluateGridChecklistAction)(GridPlan::query()->findOrFail($plan->id))['items'])->firstWhere('key', 'dates');
        $this->assertFalse($dates['passed']);
        $this->assertStringContainsString('less than 10 minutes apart', (string) $dates['detail']);

        // A calendar drag that swaps them is allowed, and warns on the plan and the post.
        $first->update(['planned_local_time' => '12:00']);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $post = $first->fresh()?->post;
        $this->assertNotNull($post);

        $this->actingAs($this->owner())->post(route('posts.reschedule', $post), ['days' => -1])->assertRedirect();

        $dates = collect((new EvaluateGridChecklistAction)(GridPlan::query()->findOrFail($plan->id))['items'])->firstWhere('key', 'dates');
        $this->assertStringContainsString('This puts tile 1 before tile 2. The grid will be out of order.', (string) $dates['detail']);

        $checklist = (new EvaluatePostChecklistAction)($post->fresh() ?? $post);
        $order = collect($checklist['items'])->firstWhere('key', 'grid_order');
        $this->assertFalse($order['passed']);
        $this->assertTrue($order['soft']);
    }

    public function test_schedule_approved_moves_only_approved_posts_through_their_own_checklists(): void
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);
        $plan->tiles->each(fn (GridTile $tile, int $i) => $tile->update(['planned_local_date' => '2026-11-0'.(5 - $i), 'planned_local_time' => '09:00', 'caption_idea' => 'Caption '.$i]));
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        [$approved, $broken, $waiting] = $plan->tiles()->get()->map(fn (GridTile $tile) => $tile->post()->firstOrFail())->all();
        $approved->update(['status' => PostStatus::Approved]);
        $broken->update(['status' => PostStatus::Approved, 'master_caption' => null]);
        $waiting->update(['status' => PostStatus::WaitingClient]);

        $this->actingAs($this->owner())->post(route('grids.schedule-approved', $plan))->assertRedirect();

        $this->assertSame(PostStatus::Scheduled, $approved->fresh()?->status);
        $this->assertSame(PostStatus::Approved, $broken->fresh()?->status, 'A failing checklist is never scheduled.');
        $this->assertSame(PostStatus::WaitingClient, $waiting->fresh()?->status);
    }

    public function test_replanning_dates_recalls_vendor_held_posts_and_sends_them_again(): void
    {
        $this->org->update(['upload_post_key' => 'org-key']);
        Http::fake(['*/uploadposts/schedule/*' => Http::response(['success' => true], 200)]);

        $plan = $this->planWithTiles(1, GridPlanStatus::Approved);
        $plan->tiles()->firstOrFail()->update(['planned_local_date' => '2026-11-01', 'planned_local_time' => '09:00']);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $post = $plan->tiles()->firstOrFail()->post()->firstOrFail();
        $post->update(['status' => PostStatus::Scheduled, 'publish_dispatch_id' => 'dispatch-1', 'master_caption' => 'x']);
        $post->targets()->update(['external_post_id' => 'job-ig', 'publish_attempt_id' => 'attempt-1', 'publish_requested_at' => now(), 'status' => PostTargetStatus::Pending]);

        app(PlanGridScheduleAction::class)->apply($plan->fresh() ?? $plan, $this->owner(), ['start_date' => '2026-11-04', 'time' => '10:00', 'cadence' => 'daily']);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/uploadposts/schedule/job-ig'));
        $target = $post->targets()->firstOrFail();
        $this->assertSame('2026-11-04', $target->scheduled_local_date?->toDateString());
        $this->assertNull($target->external_post_id);
        Bus::assertDispatched(SendScheduledPostToVendorJob::class);
    }
}
