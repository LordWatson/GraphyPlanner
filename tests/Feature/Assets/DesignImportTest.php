<?php

namespace Tests\Feature\Assets;

use App\Enums\AssetSource;
use App\Enums\DesignImportProvider;
use App\Enums\Role;
use App\Jobs\ProbeAssetJob;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\DesignImportConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\DesignImports\CanvaImportSource;
use App\Services\DesignImports\DropboxImportSource;
use App\Services\DesignImports\FakeDesignImportSource;
use App\Services\DesignImports\GoogleDriveImportSource;
use App\Support\DesignImports\DesignFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step M-5.9 — design/cloud imports. Uses a `FakeDesignImportSource` bound to each provider's
 * concrete class, so no real OAuth or network is involved.
 */
class DesignImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        config([
            'design-imports.canva.enabled' => true,
            'design-imports.canva.client_id' => 'id',
            'design-imports.canva.client_secret' => 'secret',
            'design-imports.google_drive.enabled' => true,
            'design-imports.dropbox.enabled' => true,
        ]);
    }

    private function bindFake(string $class, DesignImportProvider $provider, bool $dead = false): FakeDesignImportSource
    {
        $fake = new FakeDesignImportSource(
            provider: $provider,
            files: [new DesignFile('design-1', 'Launch banner', 'image/png')],
            contents: 'the-bytes',
            dead: $dead,
        );
        $this->app->instance($class, $fake);

        return $fake;
    }

    private function connection(DesignImportProvider $provider): DesignImportConnection
    {
        return DesignImportConnection::create([
            'org_id' => $this->org->id,
            'provider' => $provider,
            'access_token' => 'token',
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_importing_creates_a_private_tagged_asset_and_probes_it(): void
    {
        Bus::fake();
        $this->bindFake(CanvaImportSource::class, DesignImportProvider::Canva);
        $this->connection(DesignImportProvider::Canva);

        $this->actingAs($this->owner)
            ->post(route('clients.design-imports.import', $this->client), [
                'provider' => 'canva',
                'file_id' => 'design-1',
            ])
            ->assertRedirect();

        $asset = Asset::query()->firstOrFail();
        $this->assertSame(AssetSource::Canva, $asset->source);
        $this->assertSame($this->client->id, $asset->client_id);
        // Stored on the private disk (signed-URL-only), not the public one.
        $this->assertSame('local', $asset->disk);
        Storage::disk('local')->assertExists($asset->path);
        Bus::assertDispatched(ProbeAssetJob::class);
    }

    public function test_an_imported_asset_can_join_a_campaign_kit(): void
    {
        $this->bindFake(CanvaImportSource::class, DesignImportProvider::Canva);
        $this->connection(DesignImportProvider::Canva);
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)
            ->post(route('clients.design-imports.import', $this->client), [
                'provider' => 'canva',
                'file_id' => 'design-1',
                'campaign_id' => $campaign->id,
            ])
            ->assertRedirect();

        $this->assertSame($campaign->id, Asset::query()->firstOrFail()->campaign_id);
    }

    public function test_each_provider_imports_through_its_own_source(): void
    {
        foreach ([
            [GoogleDriveImportSource::class, DesignImportProvider::GoogleDrive, 'google_drive', AssetSource::GoogleDrive],
            [DropboxImportSource::class, DesignImportProvider::Dropbox, 'dropbox', AssetSource::Dropbox],
        ] as [$class, $provider, $value, $assetSource]) {
            $this->bindFake($class, $provider);
            $this->connection($provider);

            $this->actingAs($this->owner)
                ->post(route('clients.design-imports.import', $this->client), [
                    'provider' => $value,
                    'file_id' => 'f',
                ])
                ->assertRedirect();

            $this->assertTrue(Asset::query()->where('source', $assetSource)->exists(), $value);
        }
    }

    public function test_a_revoked_connection_fails_cleanly_with_a_reconnect_prompt(): void
    {
        $this->bindFake(CanvaImportSource::class, DesignImportProvider::Canva, dead: true);
        $connection = $this->connection(DesignImportProvider::Canva);
        $connection->update(['revoked_at' => now()]);

        // A revoked connection isn't even "live", so importing says to connect first.
        $this->actingAs($this->owner)
            ->post(route('clients.design-imports.import', $this->client), [
                'provider' => 'canva',
                'file_id' => 'design-1',
            ])
            ->assertRedirect();

        $this->assertSame(0, Asset::query()->count());
    }

    public function test_a_dead_but_live_connection_surfaces_reconnect_on_listing(): void
    {
        $this->bindFake(CanvaImportSource::class, DesignImportProvider::Canva, dead: true);
        $this->connection(DesignImportProvider::Canva);

        $this->actingAs($this->owner)
            ->getJson(route('clients.design-imports.files', $this->client).'?provider=canva')
            ->assertStatus(409)
            ->assertJson(['needs_reconnect' => true]);
    }

    public function test_listing_returns_files_for_a_connected_provider(): void
    {
        $this->bindFake(CanvaImportSource::class, DesignImportProvider::Canva);
        $this->connection(DesignImportProvider::Canva);

        $this->actingAs($this->owner)
            ->getJson(route('clients.design-imports.files', $this->client).'?provider=canva')
            ->assertOk()
            ->assertJson(['connected' => true])
            ->assertJsonPath('files.0.id', 'design-1');
    }

    public function test_connecting_requires_a_producing_role(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->get(route('design-imports.connect', ['provider' => 'canva']))
            ->assertForbidden();
    }

    public function test_disconnect_revokes_and_scrubs_tokens(): void
    {
        $connection = $this->connection(DesignImportProvider::Canva);

        $this->actingAs($this->owner)
            ->from(route('clients.index'))
            ->delete(route('design-imports.disconnect', $connection))
            ->assertRedirect(route('clients.index'));

        $connection->refresh();
        $this->assertTrue($connection->isRevoked());
        $this->assertNull($connection->access_token);
    }
}
