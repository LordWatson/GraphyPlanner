<?php

namespace Tests\Feature\Business;

use App\Actions\AI\SuggestCaptionsFromMediaAction;
use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Contracts\VisionProvider;
use App\Enums\AssetType;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\VisionImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptionFromPhotoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function businessWorkspace(): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = (new CreatePrimaryBrandAction)($organization);
        $owner = User::factory()->create(['org_id' => $organization->id, 'role' => Role::Owner, 'email_verified_at' => now()]);

        return [$organization, $brand, $owner];
    }

    private function imageAsset(Client $brand): Asset
    {
        Storage::fake('public');
        Storage::disk('public')->put('photo.jpg', 'imagebytes');

        return Asset::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'type' => AssetType::Image,
            'disk' => 'public',
            'path' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
        ]);
    }

    /**
     * A fake vision provider: records the call and returns fixed suggestions.
     */
    private function fakeVision(AiCaptionSuggestions $return): object
    {
        return new class($return) implements VisionProvider
        {
            public int $calls = 0;

            public function __construct(private readonly AiCaptionSuggestions $return) {}

            public function captionsFromImage(Client $client, VisionImage $image, array $platforms): AiCaptionSuggestions
            {
                $this->calls++;

                return $this->return;
            }
        };
    }

    public function test_the_action_returns_guardrailed_suggestions_and_records_usage(): void
    {
        [, $brand] = $this->businessWorkspace();
        $asset = $this->imageAsset($brand);

        $this->app->instance(VisionProvider::class, $this->fakeVision(new AiCaptionSuggestions([
            new AiCaptionSuggestion(caption: 'Fresh from the oven.', hashtags: ['bakery']),
        ])));

        $result = app(SuggestCaptionsFromMediaAction::class)($brand, $asset);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('Fresh from the oven.', $result->suggestions[0]->caption);
        $this->assertDatabaseHas('ai_usage_logs', ['client_id' => $brand->id, 'method' => 'captionsFromImage', 'succeeded' => true]);
    }

    public function test_a_non_image_asset_returns_empty_without_calling_the_provider(): void
    {
        [, $brand] = $this->businessWorkspace();
        $video = Asset::factory()->for($brand)->create(['org_id' => $brand->org_id, 'type' => AssetType::Video, 'disk' => 'public', 'path' => 'clip.mp4', 'mime_type' => 'video/mp4']);

        $provider = $this->fakeVision(new AiCaptionSuggestions);
        $this->app->instance(VisionProvider::class, $provider);

        $result = app(SuggestCaptionsFromMediaAction::class)($brand, $video);

        $this->assertSame([], $result->suggestions);
        $this->assertSame(0, $provider->calls);
    }

    public function test_a_provider_failure_leaves_empty_suggestions_and_logs_usage(): void
    {
        [, $brand] = $this->businessWorkspace();
        $asset = $this->imageAsset($brand);

        $this->app->instance(VisionProvider::class, $this->fakeVision(new AiCaptionSuggestions));

        $result = app(SuggestCaptionsFromMediaAction::class)($brand, $asset);

        $this->assertSame([], $result->suggestions);
        $this->assertDatabaseHas('ai_usage_logs', ['client_id' => $brand->id, 'method' => 'captionsFromImage', 'succeeded' => false]);
    }

    public function test_the_endpoint_returns_suggestions_for_an_owner(): void
    {
        config(['ai.enabled' => true]);
        [, $brand, $owner] = $this->businessWorkspace();
        $asset = $this->imageAsset($brand);
        $this->app->instance(VisionProvider::class, $this->fakeVision(new AiCaptionSuggestions([
            new AiCaptionSuggestion(caption: 'A caption.', hashtags: ['tag']),
        ])));

        $this->actingAs($owner)
            ->postJson(route('business.media.captions', ['asset' => $asset->id]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.caption', 'A caption.');
    }

    public function test_the_endpoint_is_404_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        [, $brand, $owner] = $this->businessWorkspace();
        $asset = $this->imageAsset($brand);

        $this->actingAs($owner)
            ->postJson(route('business.media.captions', ['asset' => $asset->id]))
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_use_the_endpoint(): void
    {
        config(['ai.enabled' => true]);
        [$org, $brand] = $this->businessWorkspace();
        $asset = $this->imageAsset($brand);
        $viewer = User::factory()->create(['org_id' => $org->id, 'role' => Role::Viewer, 'email_verified_at' => now()]);

        $this->actingAs($viewer)
            ->postJson(route('business.media.captions', ['asset' => $asset->id]))
            ->assertForbidden();
    }

    public function test_an_agency_org_cannot_reach_the_endpoint(): void
    {
        config(['ai.enabled' => true]);
        $agency = Organization::factory()->create();
        $brand = Client::factory()->for($agency, 'organization')->create();
        $owner = User::factory()->create(['org_id' => $agency->id, 'role' => Role::Owner, 'email_verified_at' => now()]);
        $asset = Asset::factory()->for($brand)->create(['org_id' => $agency->id, 'type' => AssetType::Image, 'disk' => 'public', 'path' => 'p.jpg', 'mime_type' => 'image/jpeg']);

        $this->actingAs($owner)
            ->postJson(route('business.media.captions', ['asset' => $asset->id]))
            ->assertNotFound();
    }
}
