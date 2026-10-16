<?php

namespace Tests\Feature;

use App\Actions\Assets\SearchClientAssetsAction;
use App\Enums\AssetType;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_a_file_asset(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('assets', [
            'client_id' => $client->id,
            'org_id' => $org->id,
            'source' => 'upload',
            'uploaded_by' => $owner->id,
            'original_filename' => 'cover.jpg',
        ]);

        $asset = Asset::first();
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_json_request_returns_the_created_asset_instead_of_redirecting(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->postJson(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ]);

        $response->assertCreated();
        $asset = Asset::first();
        $response->assertJson([
            'asset' => [
                'id' => $asset->id,
                'url' => $asset->url,
                'original_filename' => 'cover.jpg',
                'type' => 'image',
            ],
        ]);
    }

    public function test_designer_can_upload_an_asset(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_owner_can_upload_a_video_asset(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('assets', [
            'client_id' => $client->id,
            'org_id' => $org->id,
            'source' => 'upload',
            'type' => 'video',
            'original_filename' => 'clip.mp4',
        ]);

        $asset = Asset::first();
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_video_upload_exceeding_image_limit_but_within_video_limit_succeeds(): void
    {
        Storage::fake('public');
        config(['assets.max_upload_size_kb' => 100]);
        config(['assets.max_video_upload_size_kb' => 20000]);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->create('clip.mp4', 15000, 'video/mp4'),
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_upload_rejects_disallowed_file_extensions(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_owner_can_link_a_figma_asset(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'figma',
            'url' => 'https://www.figma.com/file/abc123',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('assets', [
            'client_id' => $client->id,
            'source' => 'figma',
            'url' => 'https://www.figma.com/file/abc123',
        ]);
    }

    public function test_upload_source_requires_a_file(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.assets.store', $client), [
            'source' => 'upload',
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_viewer_cannot_upload_an_asset(): void
    {
        $org = Organization::factory()->create();
        $viewer = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($viewer)->post(route('clients.assets.store', $client), [
            'source' => 'url',
            'url' => 'https://example.com/image.jpg',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_owner_can_delete_an_uploaded_asset_and_its_file(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $path = UploadedFile::fake()->image('cover.jpg')->storeAs('assets', 'cover.jpg', 'public');
        $asset = Asset::factory()->for($client)->uploaded()->create([
            'org_id' => $org->id,
            'disk' => 'public',
            'path' => $path,
        ]);

        $response = $this->actingAs($owner)->delete(route('assets.destroy', $asset));

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_client_reviewer_sees_assets_scoped_to_their_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Asset::factory()->for($client)->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('assets', 1));
    }

    /**
     * The post editor's "Browse library" dialog pages through `clients.assets.index` (JSON),
     * newest first and scoped to the one client.
     */
    public function test_asset_library_is_paginated_newest_first_and_scoped_to_the_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();

        Asset::factory()->count(SearchClientAssetsAction::PER_PAGE + 2)->for($client)->create();
        $newest = Asset::factory()->for($client)->create([
            'original_filename' => 'newest.jpg',
            'created_at' => now()->addMinute(),
        ]);
        Asset::factory()->for($otherClient)->create(['original_filename' => 'not-mine.jpg']);

        $first = $this->actingAs($owner)->getJson(route('clients.assets.index', $client));

        $first->assertOk()
            ->assertJsonCount(SearchClientAssetsAction::PER_PAGE, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('meta.total', SearchClientAssetsAction::PER_PAGE + 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($owner)
            ->getJson(route('clients.assets.index', ['client' => $client, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonMissing(['original_filename' => 'not-mine.jpg']);
    }

    public function test_asset_library_filters_by_case_insensitive_literal_search_and_type(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $hero = Asset::factory()->for($client)->create(['original_filename' => 'Summer-HERO.jpg']);
        $heroVideo = Asset::factory()->for($client)->create([
            'original_filename' => 'summer-hero-cut.mp4',
            'type' => AssetType::Video,
        ]);
        Asset::factory()->for($client)->create(['original_filename' => 'winter.jpg']);
        $percent = Asset::factory()->for($client)->create(['original_filename' => '50%_off.png']);

        $search = $this->actingAs($owner)
            ->getJson(route('clients.assets.index', ['client' => $client, 'search' => 'summer-hero']))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data.*.id');
        $this->assertEqualsCanonicalizing([$hero->id, $heroVideo->id], $search);

        $this->actingAs($owner)
            ->getJson(route('clients.assets.index', ['client' => $client, 'search' => 'hero', 'type' => 'video']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $heroVideo->id)
            ->assertJsonPath('data.0.type', 'video');

        // `%` / `_` are matched literally, not as SQL wildcards.
        $this->actingAs($owner)
            ->getJson(route('clients.assets.index', ['client' => $client, 'search' => '%_']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $percent->id);
    }

    public function test_asset_library_rejects_an_unknown_type_filter(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)
            ->getJson(route('clients.assets.index', ['client' => $client, 'type' => 'spreadsheet']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_asset_library_is_forbidden_outside_the_users_org(): void
    {
        $client = Client::factory()->create();
        $outsider = User::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->role(Role::Owner)
            ->create();

        $this->actingAs($outsider)
            ->getJson(route('clients.assets.index', $client))
            ->assertForbidden();
    }
}
