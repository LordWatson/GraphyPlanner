<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\CreatePostsFromGridTilesAction;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridComment;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.5 — the planner page and the client's Grid tab: what each role receives, that every tile
 * kind renders, and that no token or secret ever reaches the page.
 */
class GridPlannerPageTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    private const TOKEN = 'EAAG-planner-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
    }

    public function test_the_planner_renders_every_tile_kind_for_staff_with_team_notes(): void
    {
        $plan = $this->makePlan();
        $this->addTile($plan, GridTileKind::Image, attributes: ['team_note' => 'Swap if late']);
        $this->addTile($plan, GridTileKind::Carousel);
        $this->addTile($plan, GridTileKind::Reel, attributes: ['cover_asset_id' => $this->image(1080, 1920)->id]);
        $this->addTile($plan, GridTileKind::Placeholder, attributes: ['placeholder_colour' => 'lime']);

        $this->actingAs($this->designer())->get(route('grids.edit', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('grids/edit')
                ->has('grid.tiles', 4)
                ->where('grid.tiles.0.team_note', 'Swap if late')
                ->where('grid.tiles.0.sequence', 4)
                ->where('grid.tiles.1.kind', 'carousel')
                ->has('grid.tiles.1.assets', 2)
                ->where('grid.tiles.2.kind', 'reel')
                ->where('grid.tiles.2.format', '9:16')
                ->whereNot('grid.tiles.2.cover', null)
                ->where('grid.tiles.3.placeholder_colour', 'lime')
                ->has('grid.checklist.items')
                ->where('can.update', true)
                ->where('can.delete', false)
                ->has('allowedTransitions', 2)
                ->missing('feed')
                ->loadDeferredProps(['default', 'discussion', 'library'], fn (AssertableInertia $reload) => $reload
                    ->has('feed.items')
                    ->has('comments')
                    ->has('activity')
                    ->has('studioImages')));
    }

    public function test_a_tile_with_a_post_shows_the_posts_status_and_published_caption(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        $plan->tiles()->update(['caption_idea' => 'From the tile']);
        app(CreatePostsFromGridTilesAction::class)($plan, $this->owner());
        $plan->tiles()->firstOrFail()->post()->firstOrFail()->update(['master_caption' => 'Words the client approved']);

        $this->actingAs($this->owner())->get(route('grids.edit', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('grid.tiles.0.post.status', 'draft')
                ->where('grid.tiles.0.caption', 'Words the client approved')
                ->where('grid.tiles.0.caption_idea', 'From the tile')
                ->where('grid.progress.posts', 2)
                ->where('grid.order_risk', null));
    }

    public function test_a_viewer_reads_the_planner_but_cannot_change_it(): void
    {
        $plan = $this->planWithTiles(1);

        $this->actingAs($this->viewer())->get(route('grids.edit', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.update', false)
                ->where('can.produce', false)
                ->where('allowedTransitions', []));
    }

    public function test_no_token_or_vendor_id_reaches_the_page(): void
    {
        $this->instagram->update(['meta_access_token' => self::TOKEN, 'meta_instagram_user_id' => '178', 'meta_access_token_expires_at' => now()->addDay(), 'external_profile_id' => 'vendor-profile-secret']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
        $plan = $this->planWithTiles(1);

        $content = (string) $this->actingAs($this->owner())->get(route('grids.edit', $plan))->getContent();
        $deferred = (string) $this->actingAs($this->owner())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'grids/edit', 'X-Inertia-Partial-Data' => 'feed,comments,activity,studioImages'])
            ->get(route('grids.edit', $plan))
            ->getContent();

        foreach ([$content, $deferred] as $body) {
            $this->assertStringNotContainsString(self::TOKEN, $body);
            $this->assertStringNotContainsString('vendor-profile-secret', $body);
        }
    }

    public function test_comments_carry_their_tile_and_internal_flag_for_staff(): void
    {
        $plan = $this->planWithTiles(2);
        $tile = $plan->tiles()->firstOrFail();
        GridComment::query()->create(['grid_plan_id' => $plan->id, 'grid_tile_id' => $tile->id, 'user_id' => $this->owner()->id, 'body' => 'Brighter?', 'internal_only' => true]);

        $this->actingAs($this->owner())->get(route('grids.edit', $plan))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('discussion', fn (AssertableInertia $reload) => $reload
                    ->where('comments.0.tile_position', 1)
                    ->where('comments.0.internal_only', true)));
    }

    public function test_the_grid_tab_lists_plans_and_only_shows_for_clients_with_instagram(): void
    {
        $this->planWithTiles(3);

        $this->actingAs($this->owner())->get(route('clients.grids.index', $this->client))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('clients/tabs/grids')
                ->where('tab', 'grids')
                ->where('can.viewGrids', true)
                ->where('stats.grids', 1)
                ->has('grids.data', 1)
                ->has('grids.data.0.preview', 3)
                ->has('accounts', 1));

        // A client without a live Instagram account still has the tab, which explains why there's
        // no "New grid" (a deleted account doesn't count).
        $other = Client::factory()->for($this->org, 'organization')->create();
        SocialAccount::factory()->for($other)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok]);
        SocialAccount::factory()->for($other)->create(['org_id' => $this->org->id, 'platform' => Platform::Instagram])->delete();

        $this->actingAs($this->owner())->get(route('clients.grids.index', $other))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.viewGrids', true)
                ->where('can.createGrid', false)
                ->has('accounts', 0));
    }

    public function test_the_grids_hub_lists_every_clients_grids_and_offers_new_grid(): void
    {
        $this->planWithTiles(2);
        $other = Client::factory()->for($this->org, 'organization')->create();
        GridPlan::factory()->create(['client_id' => $other->id]);
        $foreign = GridPlan::factory()->create();

        $this->actingAs($this->designer())->get(route('grids.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('grids/index')
                ->has('grids.data', 2)
                ->where('can.create', true)
                ->has('accounts', 2)
                ->where('accounts.0.client_name', fn ($name) => in_array($name, [$this->client->name, $other->name], true)));

        $this->assertNotSame($foreign->org_id, $this->org->id);

        $this->actingAs($this->owner())->get(route('grids.index', ['client_id' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('grids.data', 1));

        $this->actingAs($this->viewer())->get(route('grids.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false)->has('accounts', 0));

        $this->actingAs($this->reviewer)->get(route('grids.index'))->assertRedirect(route('portal.grids.index'));
    }

    public function test_the_campaign_workspace_lists_its_grids(): void
    {
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $this->makePlan(['campaign_id' => $campaign->id, 'status' => GridPlanStatus::Approved]);

        $this->actingAs($this->owner())->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('grids', 1)));
    }
}
