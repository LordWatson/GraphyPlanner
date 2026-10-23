<?php

namespace Tests\Feature\Business;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step B-7.3 — the Web Share Target: "Share → Alima" from the phone gallery POSTs the media here,
 * which ingests it and bounces into the quick post flow with it attached.
 */
class ShareToAlimaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    /**
     * @return array{0: Organization, 1: User, 2: Client}
     */
    private function workspace(): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        return [$organization, $owner, $brand];
    }

    public function test_shared_media_becomes_an_asset_and_lands_in_quick_post(): void
    {
        [, $owner, $brand] = $this->workspace();

        $response = $this->actingAs($owner)->post(route('business.posts.quick.share'), [
            'media' => UploadedFile::fake()->image('from-gallery.jpg'),
            'title' => 'Sourdough',
            'text' => 'Out of the oven',
        ]);

        $asset = Asset::query()->where('client_id', $brand->id)->sole();

        $response->assertRedirect(route('business.posts.quick', [
            'asset' => $asset->id,
            'text' => "Sourdough\n\nOut of the oven",
        ]));
    }

    public function test_share_requires_authentication(): void
    {
        $this->post(route('business.posts.quick.share'), [
            'media' => UploadedFile::fake()->image('x.jpg'),
        ])->assertRedirect(route('login'));
    }

    public function test_share_validates_the_media(): void
    {
        [, $owner] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.share'), [
                'media' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors('media');
    }

    public function test_an_agency_cannot_reach_the_share_target(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('business.posts.quick.share'), [
                'media' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertNotFound();
    }
}
