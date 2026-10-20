<?php

namespace Tests\Feature\Grids;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\ImageVariantStatus;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\Asset;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.4 — plan and tile actions over HTTP: create, add (insert-shift), update, reorder (with
 * the stale-version guard and split blocks), remove, split, duplicate, reopen-on-edit, and who
 * may do it.
 */
class GridPlanActionsTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        Queue::fake();
    }

    public function test_a_strategist_starts_a_grid_for_an_instagram_account(): void
    {
        $response = $this->actingAs($this->strategist())->post(route('clients.grids.store', $this->client), [
            'name' => 'November grid',
            'social_account_id' => $this->instagram->id,
            'pinned_count' => 2,
        ]);

        $plan = GridPlan::query()->firstOrFail();
        $response->assertRedirect(route('grids.edit', $plan));
        $this->assertSame(GridPlanStatus::Draft, $plan->status);
        $this->assertSame(2, $plan->pinned_count);
        $this->assertSame(1, $plan->version);
        $this->assertNotNull($plan->checklist_snapshot);
    }

    public function test_a_grid_needs_an_instagram_account_of_the_same_client(): void
    {
        $tiktok = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $theirs = SocialAccount::factory()->for($otherClient)->create(['org_id' => $this->org->id, 'platform' => Platform::Instagram]);

        foreach ([$tiktok, $theirs] as $account) {
            $this->actingAs($this->owner())
                ->post(route('clients.grids.store', $this->client), ['name' => 'Grid', 'social_account_id' => $account->id])
                ->assertSessionHasErrors('social_account_id');
        }

        $this->assertSame(0, GridPlan::query()->count());
    }

    public function test_adding_tiles_inserts_at_a_position_and_shifts_the_rest(): void
    {
        $plan = $this->planWithTiles(3);
        $original = $plan->tiles()->pluck('id')->all();
        [$a, $b] = [$this->image(), $this->image()];

        $this->actingAs($this->designer())->post(route('grids.tiles.store', $plan), [
            'at_position' => 2,
            'tiles' => [['asset_ids' => [$a->id]], ['asset_ids' => [$b->id]]],
        ])->assertRedirect();

        $order = $plan->tiles()->get();
        $this->assertSame([1, 2, 3, 4, 5], $order->pluck('position')->all());
        $this->assertSame($original[0], $order[0]->id);
        $this->assertSame([$a->id], $order[1]->assets()->pluck('assets.id')->all());
        $this->assertSame([$b->id], $order[2]->assets()->pluck('assets.id')->all());
        $this->assertSame([$original[1], $original[2]], [$order[3]->id, $order[4]->id]);
        $this->assertSame(2, $plan->fresh()?->version);
    }

    public function test_tile_kind_follows_its_media_and_placeholders_take_a_brand_colour(): void
    {
        $plan = $this->makePlan();

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [
            ['asset_ids' => [$this->image()->id, $this->image()->id]],
            ['asset_ids' => [$this->video()->id]],
            ['placeholder_colour' => 'lilac', 'team_note' => 'Product shot TBC'],
        ]])->assertRedirect();

        $tiles = $plan->tiles()->get();
        $this->assertSame([GridTileKind::Carousel, GridTileKind::Reel, GridTileKind::Placeholder], $tiles->pluck('kind')->all());
        $this->assertSame(MediaRatio::Vertical, $tiles[1]->format);
        $this->assertSame('lilac', $tiles[2]->placeholder_colour?->value);

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['placeholder_colour' => '#ff0000']]])
            ->assertSessionHasErrors('tiles.0.placeholder_colour');
    }

    public function test_another_clients_asset_is_rejected(): void
    {
        $plan = $this->makePlan();
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $theirs = $this->image(client: $otherClient);

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['asset_ids' => [$theirs->id]]]])
            ->assertSessionHasErrors('tiles.0.asset_ids.0');

        $tile = $this->addTile($plan);
        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['asset_ids' => [$theirs->id]])
            ->assertSessionHasErrors('asset_ids.0');

        $this->assertSame(1, $plan->tiles()->count());
    }

    public function test_image_studio_variants_must_be_selected_and_this_clients(): void
    {
        $plan = $this->makePlan();
        $selected = $this->variant($this->client, selected: true);
        $unpicked = $this->variant($this->client, selected: false);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $theirs = $this->variant($otherClient, selected: true);

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['variant_ids' => [$unpicked->id]]]])
            ->assertSessionHasErrors('tiles.0.variant_ids.0');
        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['variant_ids' => [$theirs->id]]]])
            ->assertSessionHasErrors('tiles.0.variant_ids.0');

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['variant_ids' => [$selected->id]]]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$selected->asset_id], $plan->tiles()->first()?->assets()->pluck('assets.id')->all());
    }

    public function test_carousel_bounds_are_enforced_on_save(): void
    {
        $plan = $this->makePlan();
        $eleven = array_map(fn () => $this->image()->id, range(1, 11));

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['kind' => 'carousel', 'asset_ids' => $eleven]]])
            ->assertSessionHasErrors();

        $tile = $this->addTile($plan, GridTileKind::Carousel);
        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['asset_ids' => $eleven])
            ->assertSessionHasErrors('asset_ids');
    }

    public function test_updating_a_tile_saves_framing_dates_and_a_reel_keeps_9_16(): void
    {
        $plan = $this->planWithTiles(1);
        $tile = $plan->tiles()->firstOrFail();
        $asset = $tile->assets()->firstOrFail();

        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), [
            'format' => '4:5',
            'asset_crops' => [$asset->id => ['x' => 0.25, 'y' => 0.6, 'zoom' => 1.5]],
            'asset_alt_texts' => [$asset->id => 'Founder at the bench'],
            'caption_idea' => 'Behind the scenes',
            'team_note' => 'Swap if the shoot runs late',
            'planned_local_date' => '2026-11-03',
            'planned_local_time' => '09:30',
        ])->assertSessionHasNoErrors();

        $tile->refresh();
        $pivot = $tile->assets()->firstOrFail()->pivot;
        $this->assertSame(MediaRatio::Portrait, $tile->format);
        $this->assertEqualsWithDelta(0.25, (float) $pivot->crop_x, 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $pivot->crop_zoom, 0.0001);
        $this->assertSame('Founder at the bench', $pivot->alt_text);
        $this->assertSame('2026-11-03', $tile->planned_local_date?->toDateString());

        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['kind' => 'image', 'format' => '9:16'])
            ->assertSessionHasErrors('format');
        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['kind' => 'reel'])
            ->assertSessionHasNoErrors();
        $this->assertSame(MediaRatio::Vertical, $tile->fresh()?->format);
    }

    public function test_reorder_with_the_current_version_moves_tiles(): void
    {
        $plan = $this->planWithTiles(3);
        $ids = $plan->tiles()->pluck('id')->all();

        $this->actingAs($this->designer())->post(route('grids.tiles.reorder', $plan), [
            'tile_ids' => array_reverse($ids),
            'expected_version' => $plan->version,
        ])->assertRedirect();

        $this->assertSame(array_reverse($ids), $plan->tiles()->pluck('id')->all());
        $this->assertSame($plan->version + 1, $plan->fresh()?->version);
    }

    public function test_a_stale_reorder_answers_409_and_changes_nothing(): void
    {
        $plan = $this->planWithTiles(3);
        $ids = $plan->tiles()->pluck('id')->all();
        $plan->update(['version' => 5]);

        $this->actingAs($this->designer())->postJson(route('grids.tiles.reorder', $plan), [
            'tile_ids' => array_reverse($ids),
            'expected_version' => 4,
        ])->assertStatus(409)->assertJson(['message' => 'Someone else changed this grid. Refresh to see it.', 'version' => 5]);

        $this->assertSame($ids, $plan->tiles()->pluck('id')->all());
        $this->assertSame(5, $plan->fresh()?->version);

        // An Inertia page gets the same refusal as a toast and a redirect back.
        $this->actingAs($this->designer())->from(route('grids.edit', $plan))->post(route('grids.tiles.reorder', $plan), [
            'tile_ids' => array_reverse($ids),
            'expected_version' => 4,
        ])->assertRedirect(route('grids.edit', $plan))->assertSessionHasErrors('version');
    }

    public function test_reorder_must_send_every_tile_and_keep_split_tiles_together(): void
    {
        $plan = $this->makePlan();
        $loose = $this->addTile($plan);
        $split = array_map(fn () => $this->addTile($plan, attributes: ['split_group_id' => 'b3a1c4e0-0000-4000-8000-000000000001']), range(1, 3));
        $ids = $plan->tiles()->pluck('id')->all();

        $this->actingAs($this->owner())->postJson(route('grids.tiles.reorder', $plan), ['tile_ids' => array_slice($ids, 1), 'expected_version' => 1])
            ->assertStatus(422);

        // Moving the loose tile inside the split breaks the block.
        $this->actingAs($this->owner())->postJson(route('grids.tiles.reorder', $plan), [
            'tile_ids' => [$split[0]->id, $loose->id, $split[1]->id, $split[2]->id],
            'expected_version' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('tile_ids');

        // Moving the whole split block is fine.
        $this->actingAs($this->owner())->postJson(route('grids.tiles.reorder', $plan), [
            'tile_ids' => [$split[0]->id, $split[1]->id, $split[2]->id, $loose->id],
            'expected_version' => 1,
        ])->assertOk()->assertJson(['version' => 2]);
    }

    public function test_removing_a_tile_closes_the_gap(): void
    {
        $plan = $this->planWithTiles(3);
        [$first, $second, $third] = $plan->tiles()->get()->all();

        $this->actingAs($this->owner())->delete(route('grids.tiles.destroy', [$plan, $second]))->assertRedirect();

        $this->assertSame([$first->id, $third->id], $plan->tiles()->pluck('id')->all());
        $this->assertSame([1, 2], $plan->tiles()->pluck('position')->all());
    }

    public function test_a_tile_from_another_grid_is_not_found_through_this_grid(): void
    {
        $plan = $this->planWithTiles(1);
        $other = $this->planWithTiles(1);
        $theirs = $other->tiles()->firstOrFail();

        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $theirs]), ['caption_idea' => 'x'])->assertNotFound();
    }

    public function test_editing_an_approved_grid_reopens_it_to_draft_but_a_note_does_not(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        $plan->update(['approved_at' => now(), 'approved_by' => $this->owner()->id, 'approved_layout_hash' => 'abc']);
        $tile = $plan->tiles()->firstOrFail();

        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['team_note' => 'Just a note'])->assertSessionHasNoErrors();
        $this->assertSame(GridPlanStatus::Approved, $plan->fresh()?->status);

        $this->actingAs($this->owner())->put(route('grids.tiles.update', [$plan, $tile]), ['format' => '1:1'])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(GridPlanStatus::Draft, $plan->status);
        $this->assertNull($plan->approved_layout_hash);
        $log = $plan->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame('Reopened by edit', $log->note);
        $this->assertSame(GridPlanStatus::Approved, $log->from_status);
    }

    public function test_changing_the_pinned_count_reopens_a_grid_with_changes_requested(): void
    {
        $plan = $this->planWithTiles(1, GridPlanStatus::ChangesRequested);

        $this->actingAs($this->owner())->put(route('grids.update', $plan), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame(GridPlanStatus::ChangesRequested, $plan->fresh()?->status);

        $this->actingAs($this->owner())->put(route('grids.update', $plan), ['pinned_count' => 3])->assertSessionHasNoErrors();
        $this->assertSame(GridPlanStatus::Draft, $plan->fresh()?->status);

        $this->actingAs($this->owner())->put(route('grids.update', $plan), ['pinned_count' => 4])->assertSessionHasErrors('pinned_count');
    }

    public function test_splitting_an_image_makes_n_correctly_sized_tiles_and_leaves_the_source_alone(): void
    {
        Storage::fake('public');
        config()->set('assets.disk', 'public');
        $plan = $this->planWithTiles(1);
        $source = $this->storedImage(3300, 1500);
        $before = Storage::disk('public')->get((string) $source->path);

        $this->actingAs($this->designer())->post(route('grids.split', $plan), ['asset_id' => $source->id, 'layout' => '1x3'])
            ->assertSessionHasNoErrors();

        $tiles = $plan->tiles()->with('assets')->get();
        $this->assertCount(4, $tiles);
        $split = $tiles->take(3);
        $this->assertCount(1, $split->pluck('split_group_id')->unique());
        $this->assertNotNull($split->first()?->split_group_id);
        $this->assertNull($tiles[3]->split_group_id);

        foreach ($split as $tile) {
            /** @var Asset $slice */
            $slice = $tile->assets->first();
            $this->assertSame($source->id, $slice->source_asset_id);
            $this->assertSame([1080, 1440], [$slice->width, $slice->height]);
            $this->assertSame([1080, 1440], array_slice((array) getimagesizefromstring((string) Storage::disk('public')->get((string) $slice->path)), 0, 2));
        }

        $this->assertSame($before, Storage::disk('public')->get((string) $source->path));
        $this->assertTrue($this->item($plan->fresh(), 'split_alignment')['passed']);
    }

    public function test_a_source_too_small_for_the_split_is_refused_with_a_friendly_message(): void
    {
        Storage::fake('public');
        $plan = $this->makePlan();
        $source = $this->storedImage(2000, 1200);

        $this->actingAs($this->owner())->post(route('grids.split', $plan), ['asset_id' => $source->id, 'layout' => '3x3'])
            ->assertSessionHasErrors(['asset_id' => 'This image is too small for a 3×3 split. It needs to be at least 3,240 × 4,320 pixels so each tile is sharp.']);

        $this->assertSame(0, $plan->tiles()->count());
        $this->assertSame(1, Asset::query()->count());
    }

    public function test_duplicating_copies_tiles_and_framing_as_a_fresh_draft(): void
    {
        $plan = $this->planWithTiles(2, GridPlanStatus::Approved);
        $tile = $plan->tiles()->firstOrFail();
        $tile->assets()->updateExistingPivot($tile->assets()->firstOrFail()->id, ['crop_x' => 0.2]);

        $this->actingAs($this->strategist())->post(route('grids.duplicate', $plan))->assertRedirect();

        $copy = GridPlan::query()->whereKeyNot($plan->id)->firstOrFail();
        $this->assertSame(GridPlanStatus::Draft, $copy->status);
        $this->assertStringEndsWith('(copy)', $copy->name);
        $this->assertCount(2, $copy->tiles);
        $this->assertEqualsWithDelta(0.2, (float) $copy->tiles()->firstOrFail()->assets()->firstOrFail()->pivot->crop_x, 0.0001);
    }

    public function test_deleting_soft_deletes_and_revokes_review_links(): void
    {
        $plan = $this->planWithTiles(1);
        $token = (new CreateReviewTokenAction)($this->client, gridPlan: $plan)['model'];

        $this->actingAs($this->designer())->delete(route('grids.destroy', $plan))->assertForbidden();
        $this->actingAs($this->strategist())->delete(route('grids.destroy', $plan))->assertRedirect(route('clients.grids.index', $this->client));

        $this->assertSoftDeleted($plan);
        $this->assertNotNull($token->fresh()?->revoked_at);
    }

    public function test_a_designer_can_edit_but_a_viewer_and_a_client_reviewer_cannot(): void
    {
        $plan = $this->planWithTiles(1);
        $tile = $plan->tiles()->firstOrFail();

        $this->actingAs($this->designer())->put(route('grids.tiles.update', [$plan, $tile]), ['caption_idea' => 'Hello'])->assertSessionHasNoErrors();

        foreach ([$this->viewer(), $this->reviewer] as $user) {
            $this->actingAs($user)->put(route('grids.tiles.update', [$plan, $tile]), ['caption_idea' => 'Nope'])->assertForbidden();
            $this->actingAs($user)->post(route('grids.tiles.store', $plan), ['tiles' => [['placeholder_colour' => 'teal']]])->assertForbidden();
            $this->actingAs($user)->post(route('clients.grids.store', $this->client), ['name' => 'x', 'social_account_id' => $this->instagram->id])->assertForbidden();
        }

        $this->assertSame('Hello', $tile->fresh()?->caption_idea);
    }

    public function test_a_grid_out_with_the_client_cannot_be_edited(): void
    {
        $plan = $this->planWithTiles(1, GridPlanStatus::WaitingClient);

        $this->actingAs($this->owner())->post(route('grids.tiles.store', $plan), ['tiles' => [['placeholder_colour' => 'teal']]])->assertForbidden();
    }

    public function test_preview_mode_cannot_write(): void
    {
        $this->seed(PreviewSeeder::class);
        $client = Client::query()->withoutGlobalScopes()->firstWhere('name', 'Fixture & Co');
        $plan = GridPlan::factory()->create(['client_id' => $client?->id]);
        $version = $plan->version;

        $this->post(route('preview.clients.grids.store', $client), ['name' => 'x'])->assertForbidden();
        $this->put(route('preview.grids.update', $plan), ['name' => 'Tampered'])->assertForbidden();
        $this->post(route('preview.grids.tiles.store', $plan), ['tiles' => [['placeholder_colour' => 'teal']]])->assertForbidden();
        $this->post(route('preview.grids.tiles.reorder', $plan), ['tile_ids' => [1], 'expected_version' => 1])->assertForbidden();
        $this->post(route('preview.grids.transition', $plan), ['to' => 'internal_review'])->assertForbidden();

        $this->assertSame($version, $plan->fresh()?->version);
        $this->assertSame(0, $plan->tiles()->count());
    }

    private function variant(Client $client, bool $selected): AiImageVariant
    {
        $generation = AiImageGeneration::factory()->create(['org_id' => $client->org_id, 'client_id' => $client->id]);
        $asset = $this->image(client: $client);

        return AiImageVariant::factory()->create([
            'generation_id' => $generation->id,
            'org_id' => $client->org_id,
            'status' => ImageVariantStatus::Ready,
            'asset_id' => $asset->id,
            'selected_at' => $selected ? now() : null,
        ]);
    }

    private function storedImage(int $width, int $height): Asset
    {
        $path = "assets/{$this->org->id}/{$this->client->id}/source.jpg";
        Storage::disk('public')->put($path, (string) ImageManager::gd()->create($width, $height)->fill('#205557')->toJpeg(80));

        return Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'source' => AssetSource::Upload,
            'type' => AssetType::Image,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'original_filename' => 'banner.jpg',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(?GridPlan $plan, string $key): array
    {
        return collect($plan?->checklist_snapshot['items'] ?? [])->firstWhere('key', $key) ?? [];
    }
}
