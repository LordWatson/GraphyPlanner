<?php

namespace Tests\Feature\AI;

use App\Enums\AssetType;
use App\Enums\ReferenceImageRole;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\AI\ClientContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step 16.4: Brand Brain visual references (limit, image-only, same-client, normalised copies,
 * fingerprint invalidation, authz).
 */
class BrandReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function image(?Client $client = null, int $width = 2000, int $height = 1500): Asset
    {
        $client ??= $this->client;
        $gd = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($gd);
        $path = 'assets/'.uniqid().'.png';
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return Asset::factory()->uploaded()->create([
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'type' => AssetType::Image,
            'mime_type' => 'image/png',
            'path' => $path,
        ]);
    }

    public function test_owner_saves_ordered_role_tagged_references_with_normalised_copies(): void
    {
        $logo = $this->image();
        $style = $this->image();

        $this->actingAs($this->owner)
            ->put(route('clients.brand-brain.references.update', $this->client), ['references' => [
                ['asset_id' => $logo->id, 'role' => 'logo'],
                ['asset_id' => $style->id, 'role' => 'style'],
            ]])
            ->assertRedirect(route('clients.brand-brain.edit', $this->client));

        $rows = BrandBrain::query()->where('client_id', $this->client->id)->firstOrFail()->referenceAssets;

        $this->assertCount(2, $rows);
        $this->assertSame([$logo->id, $style->id], $rows->pluck('asset_id')->all());
        $this->assertSame(ReferenceImageRole::Logo, $rows[0]->role);
        $this->assertSame('image/webp', $rows[0]->normalized_mime_type);

        $normalized = Storage::disk('public')->get((string) $rows[0]->normalized_path);
        $this->assertSame(1024, getimagesizefromstring((string) $normalized)[0]);

        $this->actingAs($this->owner)->get(route('clients.brand-brain.edit', $this->client))
            ->assertInertia(fn ($page) => $page->has('references', 2)->where('references.0.role', 'logo'));
    }

    public function test_removing_a_reference_deletes_its_normalised_copy(): void
    {
        $logo = $this->image();
        $this->actingAs($this->owner)->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $logo->id, 'role' => 'logo']]]);
        $path = $this->client->fresh()->brandBrain->referenceAssets->first()->normalized_path;

        $this->actingAs($this->owner)->put(route('clients.brand-brain.references.update', $this->client), ['references' => []]);

        $this->assertCount(0, $this->client->fresh()->brandBrain->referenceAssets);
        Storage::disk('public')->assertMissing((string) $path);
    }

    public function test_the_reference_limit_is_enforced(): void
    {
        Config::set('ai.images.max_references', 2);
        $assets = [$this->image(width: 20, height: 20), $this->image(width: 20, height: 20), $this->image(width: 20, height: 20)];

        $this->actingAs($this->owner)
            ->put(route('clients.brand-brain.references.update', $this->client), ['references' => array_map(
                fn (Asset $asset) => ['asset_id' => $asset->id, 'role' => 'style'],
                $assets,
            )])
            ->assertSessionHasErrors('references');
    }

    public function test_non_image_and_link_only_assets_are_rejected(): void
    {
        $pdf = Asset::factory()->uploaded()->create(['client_id' => $this->client->id, 'type' => AssetType::Document, 'mime_type' => 'application/pdf']);
        $link = Asset::factory()->create(['client_id' => $this->client->id, 'type' => AssetType::Image]);

        foreach ([$pdf, $link] as $asset) {
            $this->actingAs($this->owner)
                ->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $asset->id, 'role' => 'style']]])
                ->assertSessionHasErrors('references');
        }
    }

    public function test_another_clients_asset_is_rejected(): void
    {
        $foreign = $this->image(Client::factory()->for($this->org, 'organization')->create(), 20, 20);

        $this->actingAs($this->owner)
            ->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $foreign->id, 'role' => 'logo']]])
            ->assertSessionHasErrors('references');
    }

    public function test_an_internal_role_cannot_be_submitted(): void
    {
        $asset = $this->image(width: 20, height: 20);

        $this->actingAs($this->owner)
            ->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $asset->id, 'role' => 'draft']]])
            ->assertSessionHasErrors('references.0.role');
    }

    public function test_the_brand_fingerprint_changes_when_references_change(): void
    {
        BrandBrain::factory()->for($this->client)->create();
        $before = (new ClientContextBuilder)->fingerprint($this->client->fresh());

        $asset = $this->image(width: 20, height: 20);
        $this->actingAs($this->owner)->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $asset->id, 'role' => 'logo']]]);
        $afterAdd = (new ClientContextBuilder)->fingerprint($this->client->fresh());

        $this->actingAs($this->owner)->put(route('clients.brand-brain.references.update', $this->client), ['references' => [['asset_id' => $asset->id, 'role' => 'style']]]);
        $afterRole = (new ClientContextBuilder)->fingerprint($this->client->fresh());

        $this->assertNotSame($before, $afterAdd);
        $this->assertNotSame($afterAdd, $afterRole);
    }

    public function test_designers_viewers_and_other_orgs_cannot_save_references(): void
    {
        $asset = $this->image(width: 20, height: 20);
        $payload = ['references' => [['asset_id' => $asset->id, 'role' => 'logo']]];

        foreach ([Role::Designer, Role::Viewer] as $role) {
            $user = User::factory()->for($this->org, 'organization')->role($role)->create();
            $this->actingAs($user)->put(route('clients.brand-brain.references.update', $this->client), $payload)->assertForbidden();
        }

        $stranger = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();
        $this->actingAs($stranger)->put(route('clients.brand-brain.references.update', $this->client), $payload)->assertForbidden();
    }
}
