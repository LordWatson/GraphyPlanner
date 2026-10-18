<?php

namespace Tests\Feature\AI;

use App\Actions\AI\Images\PruneAiImagesAction;
use App\Enums\AssetSource;
use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Steps 16.10, 16.11 and 16.13: portal scoping, Settings → AI usage, pruning and AI disclosure.
 */
class ImageStudioHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private function aiVariant(Client $client, array $variant = []): AiImageVariant
    {
        $generation = AiImageGeneration::factory()->create(['client_id' => $client->id, 'org_id' => $client->org_id]);
        $variantModel = AiImageVariant::factory()->create([
            'generation_id' => $generation->id,
            'org_id' => $client->org_id,
            'status' => ImageVariantStatus::Ready,
            'preview_disk' => 'public',
            'preview_path' => 'ai-images/preview-'.uniqid().'.webp',
            ...$variant,
        ]);

        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'source' => AssetSource::Ai,
            'disk' => 'public',
            'path' => 'ai-images/original-'.uniqid().'.png',
            'is_ai_generated' => true,
            'ai_image_variant_id' => $variantModel->id,
            'variant_group_id' => $generation->uuid,
        ]);

        $variantModel->update(['asset_id' => $asset->id]);
        Storage::disk('public')->put((string) $asset->path, 'x');
        Storage::disk('public')->put((string) $variantModel->preview_path, 'x');

        return $variantModel->fresh();
    }

    public function test_prune_removes_old_unselected_and_discarded_files_but_keeps_attached_ones(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $client = Client::factory()->create();
        $old = now()->subDays(40);

        $unselected = $this->aiVariant($client, ['created_at' => $old]);
        $discarded = $this->aiVariant($client, ['discarded_at' => $old, 'selected_at' => $old]);
        $attached = $this->aiVariant($client, ['created_at' => $old]);
        $recent = $this->aiVariant($client);

        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id]);
        $post->assets()->attach($attached->asset_id);

        $unselectedPath = $unselected->asset->path;
        $result = app(PruneAiImagesAction::class)();

        $this->assertSame(2, $result['variants']);
        $this->assertSame(4, $result['files']);
        Storage::disk('public')->assertMissing((string) $unselectedPath);
        $this->assertNull($unselected->fresh()->asset_id);
        $this->assertNull($discarded->fresh()->asset_id);
        $this->assertNotNull($attached->fresh()->asset_id);
        Storage::disk('public')->assertExists((string) $attached->asset->path);
        $this->assertNotNull($recent->fresh()->asset_id);
    }

    public function test_the_prune_command_runs(): void
    {
        $this->artisan('ai-images:prune', ['--days' => 10])->assertSuccessful();
    }

    public function test_tiktok_publish_sets_the_ai_label_for_ai_media(): void
    {
        Http::fake(['*' => Http::response(['request_id' => 'up-tt'], 200)]);

        $organization = Organization::factory()->create(['upload_post_key' => 'key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create(['platform' => Platform::TikTok]);
        $client = Client::find($account->client_id);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'is_ai_generated' => true, 'source' => AssetSource::Ai]);
        $post->assets()->attach($asset);
        $post->load('assets');

        $this->assertTrue($post->hasAiGeneratedMedia());

        $results = (new UploadPostAdapter)->publish($post, new Collection([$account]));

        $this->assertContains('is_ai_generated', $results->first()->sentFields);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'is_ai_generated'));
    }

    public function test_client_reviewer_sees_only_the_attached_asset_never_the_set(): void
    {
        $client = Client::factory()->create();
        $attached = $this->aiVariant($client);
        $unattached = $this->aiVariant($client);
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::WaitingClient]);
        $post->assets()->attach($attached->asset_id);

        $reviewer = User::factory()->role(Role::ClientReviewer)->create(['org_id' => $client->org_id, 'client_id' => $client->id]);

        $response = $this->actingAs($reviewer)->get(route('portal.posts.show', $post));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('post.assets', 1)
            ->where('post.assets.0.id', $attached->asset_id)
            ->missing('imageStudio')
        );
        $response->assertDontSee((string) $attached->generation->uuid);
        $response->assertDontSee((string) $unattached->asset->path);
    }

    public function test_ai_usage_settings_show_figures_to_owners_only(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => 200]);
        $client = Client::factory()->for($org, 'organization')->create();
        $this->aiVariant($client);
        $this->aiVariant($client, ['quality_tier' => ImageQualityTier::Final]);
        AiUsageLog::query()->create([
            'org_id' => $org->id, 'provider' => 'gemini', 'model' => 'm', 'method' => 'image.generate',
            'succeeded' => true, 'cost_estimate' => 0.201,
        ]);
        AiUsageLog::query()->create([
            'org_id' => $org->id, 'provider' => 'gemini', 'model' => 'm', 'method' => 'image.generate',
            'succeeded' => false, 'cost_estimate' => 0,
        ]);

        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($owner)->get(route('ai-usage.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/ai-usage')
                ->where('usage.used', 2)
                ->where('usage.quota', 200)
                ->where('usage.remaining', 198)
                ->where('usage.finals', 1)
                ->where('usage.cost_estimate', 0.2)
                ->where('usage.failed', 1)
            );

        $this->actingAs($strategist)->get(route('ai-usage.edit'))->assertForbidden();
        $this->actingAs($strategist)->put(route('ai-usage.update'), ['ai_monthly_image_quota' => 5])->assertForbidden();
    }

    public function test_owner_can_set_and_clear_the_quota(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->put(route('ai-usage.update'), ['ai_monthly_image_quota' => 150])->assertRedirect(route('ai-usage.edit'));
        $this->assertSame(150, $org->fresh()->ai_monthly_image_quota);

        $this->actingAs($owner)->put(route('ai-usage.update'), ['ai_monthly_image_quota' => ''])->assertRedirect();
        $this->assertNull($org->fresh()->ai_monthly_image_quota);

        $this->actingAs($owner)->put(route('ai-usage.update'), ['ai_monthly_image_quota' => -1])->assertSessionHasErrors('ai_monthly_image_quota');
    }
}
