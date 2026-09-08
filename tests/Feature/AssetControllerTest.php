<?php

namespace Tests\Feature;

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
}
