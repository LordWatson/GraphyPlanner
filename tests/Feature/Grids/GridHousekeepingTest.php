<?php

namespace Tests\Feature\Grids;

use App\Actions\Grids\PruneGridPlansAction;
use App\Enums\GridPlanStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.13 — the preview fixture's grid (idempotent, rendered read-only) and `grids:prune`.
 */
class GridHousekeepingTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    public function test_the_preview_seeder_adds_one_approved_nine_tile_grid_and_is_idempotent(): void
    {
        $this->seed(PreviewSeeder::class);
        $this->seed(PreviewSeeder::class);

        $client = Client::query()->withoutGlobalScopes()->firstWhere('name', 'Fixture & Co');
        $plans = GridPlan::query()->withoutGlobalScopes()->where('client_id', $client?->id)->get();

        $this->assertCount(1, $plans);
        $plan = $plans->first();
        $this->assertSame(GridPlanStatus::Approved, $plan?->status);
        $this->assertSame(9, GridTile::query()->where('grid_plan_id', $plan?->id)->count());
        $this->assertSame(6, GridTile::query()->where('grid_plan_id', $plan?->id)->whereNotNull('post_id')->count());
        $this->assertSame(3, GridTile::query()->where('grid_plan_id', $plan?->id)->whereNotNull('split_group_id')->count());
        $this->assertNotNull($plan?->approved_layout_hash);

        $this->get(route('preview.clients.grids.index', $client))->assertOk();
        $this->get(route('preview.grids.edit', $plan))->assertOk();
        $this->get(route('preview.grids.schedule.preview', ['grid' => $plan, 'start_date' => '2026-11-01', 'time' => '09:00', 'cadence' => 'daily']))->assertOk();
    }

    public function test_prune_soft_deletes_old_archived_grids_and_keeps_slices_a_post_still_uses(): void
    {
        $this->setUpGridOrg();
        $old = $this->planWithTiles(2, GridPlanStatus::Archived);
        $recent = $this->planWithTiles(1, GridPlanStatus::Archived);
        $draft = $this->planWithTiles(1);
        GridPlan::query()->whereKey([$old->id, $draft->id])->update(['updated_at' => now()->subMonths(13)]);

        $source = $this->image(3240, 1440);
        $usedSlice = $this->image();
        $usedSlice->update(['source_asset_id' => $source->id]);
        $unusedSlice = $this->image();
        $unusedSlice->update(['source_asset_id' => $source->id]);
        [$first, $second] = $old->tiles()->get()->all();
        $first->assets()->sync([$usedSlice->id => ['sort_order' => 0]]);
        $second->assets()->sync([$unusedSlice->id => ['sort_order' => 0]]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->assets()->attach($usedSlice->id, ['sort_order' => 0]);

        $this->artisan('grids:prune')->expectsOutputToContain('Pruned 1 archived grid plan(s), 1 unused split image(s).')->assertSuccessful();

        $this->assertSoftDeleted($old);
        $this->assertNotSoftDeleted($recent);
        $this->assertNotSoftDeleted($draft);
        $this->assertNotSoftDeleted($usedSlice);
        $this->assertSoftDeleted($unusedSlice);
        $this->assertNotSoftDeleted($source);

        $this->assertSame(['plans' => 0, 'assets' => 0], app(PruneGridPlansAction::class)());
        $this->assertSame(1, Asset::query()->withoutGlobalScopes()->onlyTrashed()->count());
    }
}
