<?php

namespace Tests\Feature\AI;

use App\Actions\AI\Images\RenderImageVariantAction;
use App\Contracts\ImageProvider;
use App\Enums\AssetSource;
use App\Enums\ImageCapability;
use App\Enums\ImageGenerationStatus;
use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Exceptions\AI\ImageSafetyBlockedException;
use App\Jobs\GenerateImageVariantJob;
use App\Jobs\PrepareImageGenerationJob;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiPostIdea;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 16 (Steps 16.3, 16.7–16.10): the AI Image Studio end to end over HTTP, with a fake
 * ImageProvider (the queue is `sync` in tests, so every job runs inline).
 */
class AiImageStudioTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    private AiPostIdea $idea;

    /** @var array<int, string> calls the fake provider received ("generate:draft", "edit:draft", …) */
    public static array $calls = [];

    /** @var array<int, int> zero-based call indexes the fake provider should safety-block */
    public static array $blockCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
        Storage::fake('public');
        Storage::fake('local');
        RateLimiter::clear('ai-images:org:1');

        self::$calls = [];
        self::$blockCalls = [];
        $this->app->instance(ImageProvider::class, $this->fakeProvider());

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->idea = AiPostIdea::query()->create([
            'org_id' => $this->org->id,
            'client_id' => $this->client->id,
            'title' => 'Autumn menu launch',
            'description' => 'Show the new pumpkin pastry.',
            'platforms' => ['instagram'],
            'suggested_caption' => 'Autumn is here. #autumn #pastry',
        ]);
    }

    private function fakeProvider(): ImageProvider
    {
        return new class implements ImageProvider
        {
            public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage
            {
                return $this->render('generate', $options);
            }

            public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage
            {
                return $this->render('edit', $options);
            }

            public function supports(ImageCapability $capability): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function model(ImageQualityTier $tier): string
            {
                return 'fake-'.$tier->value;
            }

            public function costEstimate(ImageQualityTier $tier): float
            {
                return $tier === ImageQualityTier::Final ? 0.134 : 0.067;
            }

            private function render(string $method, ImageRenderOptions $options): GeneratedImage
            {
                $index = count(AiImageStudioTest::$calls);
                AiImageStudioTest::$calls[] = $method.':'.$options->tier->value.':refs='.count($options->references);

                if (in_array($index, AiImageStudioTest::$blockCalls, true)) {
                    throw new ImageSafetyBlockedException('blocked');
                }

                $image = imagecreatetruecolor(40, 50);
                ob_start();
                imagepng($image);
                $bytes = (string) ob_get_clean();

                return new GeneratedImage('image/png', $bytes, 40, 50, 'fake', $this->model($options->tier), 'req-'.$index, $this->costEstimate($options->tier));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'aspect_ratio' => '4:5',
            'variant_count' => 4,
            ...$overrides,
        ];
    }

    private function generateSet(array $overrides = []): AiImageGeneration
    {
        $this->actingAs($this->owner)
            ->post(route('ai-post-ideas.images.store', $this->idea), $this->payload($overrides))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return AiImageGeneration::query()->latest('id')->firstOrFail();
    }

    public function test_generating_a_set_renders_four_variants_into_four_ai_assets(): void
    {
        $generation = $this->generateSet();

        $this->assertSame(ImageGenerationStatus::Ready, $generation->status);
        $this->assertSame('fake', $generation->provider);
        $this->assertNotNull($generation->brief);
        $this->assertNotNull($generation->brief_hash);
        $this->assertCount(4, $generation->variants);
        $this->assertSame(4, Asset::query()->where('source', AssetSource::Ai)->where('is_ai_generated', true)->count());

        $asset = Asset::query()->where('is_ai_generated', true)->firstOrFail();
        $this->assertSame($generation->uuid, $asset->variant_group_id);
        $this->assertNotNull($asset->ai_image_variant_id);
        $this->assertStringStartsWith("ai-images/{$this->org->id}/{$this->client->id}/", (string) $asset->path);
        Storage::disk('local')->assertExists((string) $asset->path);

        // Distinct seeds so a set shows range, not near-duplicates.
        $this->assertCount(4, $generation->variants->pluck('seed')->unique());

        $variant = $generation->variants->first();
        $this->assertNotNull($variant->preview_path);
        Storage::disk('local')->assertExists((string) $variant->preview_path);

        $this->assertDatabaseCount('ai_usage_logs', 4);
        $this->assertDatabaseHas('ai_usage_logs', ['method' => 'image.generate', 'succeeded' => true, 'org_id' => $this->org->id]);
    }

    public function test_one_safety_block_leaves_a_partially_failed_set_with_three_usable_images(): void
    {
        self::$blockCalls = [1];

        $generation = $this->generateSet();

        $this->assertSame(ImageGenerationStatus::PartiallyFailed, $generation->status);
        $this->assertSame(3, $generation->variants->where('status', ImageVariantStatus::Ready)->count());

        $failed = $generation->variants->firstWhere('status', ImageVariantStatus::Failed);
        $this->assertSame('safety_blocked', $failed->error_code?->value);
        $this->assertNull($failed->asset_id);
        $this->assertDatabaseHas('ai_usage_logs', ['succeeded' => false, 'error_code' => 'safety_blocked', 'cost_estimate' => 0]);
    }

    public function test_a_double_submit_with_the_same_request_id_creates_one_set(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->owner)->post(route('ai-post-ideas.images.store', $this->idea), $payload);
        $this->actingAs($this->owner)->post(route('ai-post-ideas.images.store', $this->idea), $payload);

        $this->assertSame(1, AiImageGeneration::query()->count());
        $this->assertSame(4, AiImageVariant::query()->count());
        $this->assertCount(4, self::$calls);
    }

    public function test_a_job_retry_never_renders_a_variant_twice(): void
    {
        $generation = $this->generateSet();
        $variant = $generation->variants->first();

        (new GenerateImageVariantJob($variant->id))->handle(app(RenderImageVariantAction::class));

        $this->assertCount(4, self::$calls);
        $this->assertSame(4, Asset::query()->where('is_ai_generated', true)->count());
    }

    public function test_jobs_land_on_the_dedicated_ai_images_queue(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('ai-post-ideas.images.store', $this->idea), $this->payload());

        Queue::assertPushedOn('ai-images', PrepareImageGenerationJob::class);

        $generation = AiImageGeneration::query()->firstOrFail();
        $this->assertSame(ImageGenerationStatus::Queued, $generation->status);
        $this->assertSame(4, $generation->variants()->count());
        $this->assertSame('ai-images', (new GenerateImageVariantJob(1))->queue);
    }

    public function test_the_per_org_rate_limit_rejects_with_a_friendly_error(): void
    {
        Config::set('ai.images.sets_per_minute', 1);
        RateLimiter::clear('ai-images:org:'.$this->org->id);

        $this->generateSet(['variant_count' => 2]);

        $this->actingAs($this->owner)
            ->post(route('ai-post-ideas.images.store', $this->idea), $this->payload(['variant_count' => 2]))
            ->assertSessionHasErrors('imageStudio');

        $this->assertSame(1, AiImageGeneration::query()->count());
    }

    public function test_the_monthly_quota_rejects_with_a_friendly_error(): void
    {
        $this->org->update(['ai_monthly_image_quota' => 3]);

        $this->actingAs($this->owner)
            ->post(route('ai-post-ideas.images.store', $this->idea), $this->payload())
            ->assertSessionHasErrors(['imageStudio' => "You've used this month's images. Your owner can raise the limit."]);

        $this->assertSame(0, AiImageGeneration::query()->count());
        $this->assertSame([], self::$calls);
    }

    public function test_refine_creates_a_child_variant_through_edit(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);
        $parent = $generation->variants->first();

        $this->actingAs($this->owner)
            ->post(route('ai-image-variants.refine', $parent), ['instruction' => 'Warmer light', 'request_id' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $child = AiImageVariant::query()->where('parent_variant_id', $parent->id)->firstOrFail();
        $this->assertSame(ImageVariantKind::Refine, $child->kind);
        $this->assertSame(ImageVariantStatus::Ready, $child->status);
        $this->assertSame('Warmer light', $child->instruction);
        $this->assertSame($generation->id, $child->generation_id);
        $this->assertSame('edit:draft:refs=0', end(self::$calls));
        $this->assertDatabaseHas('ai_usage_logs', ['method' => 'image.edit', 'ai_image_variant_id' => $child->id]);
    }

    public function test_regenerate_replaces_the_tile_with_a_new_seed(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);
        $parent = $generation->variants->first();

        $this->actingAs($this->owner)
            ->post(route('ai-image-variants.regenerate', $parent), ['request_id' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $child = AiImageVariant::query()->where('parent_variant_id', $parent->id)->firstOrFail();
        $this->assertSame(ImageVariantKind::Regenerate, $child->kind);
        $this->assertNotNull($parent->fresh()->discarded_at);
        $this->assertSame(ImageGenerationStatus::Ready, $generation->fresh()->status);
    }

    public function test_make_final_uses_the_final_tier_only_on_finalise_with_the_draft_as_reference(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);
        $this->assertNotContains(true, array_map(fn ($call) => str_contains($call, ':final:'), self::$calls));

        $draft = $generation->variants->first();
        $requestId = (string) Str::uuid();

        $this->actingAs($this->owner)->post(route('ai-image-variants.finalize', $draft), ['request_id' => $requestId]);
        // A double click (same request) or a second "Make final" never pays for Pro twice.
        $this->actingAs($this->owner)->post(route('ai-image-variants.finalize', $draft), ['request_id' => $requestId]);
        $this->actingAs($this->owner)->post(route('ai-image-variants.finalize', $draft), ['request_id' => (string) Str::uuid()]);

        $finals = AiImageVariant::query()->where('kind', ImageVariantKind::Final->value)->get();
        $this->assertCount(1, $finals);
        $this->assertSame(ImageQualityTier::Final, $finals->first()->quality_tier);
        $this->assertSame('generate:final:refs=1', end(self::$calls));
        $this->assertNotNull($draft->fresh()->selected_at);
    }

    public function test_follow_ups_count_against_the_quota(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);
        $this->org->update(['ai_monthly_image_quota' => 2]);

        $this->actingAs($this->owner)
            ->post(route('ai-image-variants.refine', $generation->variants->first()), ['instruction' => 'Brighter', 'request_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('imageStudio');

        $this->assertSame(2, AiImageVariant::query()->count());
    }

    public function test_use_in_post_creates_the_ideas_draft_attaches_images_and_logs_activity(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);
        $ids = $generation->variants->pluck('id')->all();

        $response = $this->actingAs($this->owner)
            ->post(route('ai-image-generations.use', $generation), ['variant_ids' => $ids]);

        $post = Post::query()->firstOrFail();
        $response->assertRedirect(route('posts.edit', $post));

        $this->assertSame($post->id, $this->idea->fresh()->post_id);
        $this->assertSame(PostStatus::Idea, $post->status);
        $this->assertCount(2, $post->assets);
        $this->assertTrue(collect($post->checklist_snapshot['items'])->firstWhere('key', 'media')['passed']);
        $this->assertDatabaseHas('post_activity_logs', ['post_id' => $post->id, 'note' => 'Added 2 AI images']);
        $this->assertSame(2, AiImageVariant::query()->whereNotNull('selected_at')->count());
    }

    public function test_attaching_to_an_existing_post_never_changes_its_status(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($this->owner)
            ->post(route('clients.ai.images.store', $this->client), $this->payload(['variant_count' => 1, 'post_id' => $post->id]))
            ->assertSessionHasNoErrors();

        $generation = AiImageGeneration::query()->firstOrFail();
        $this->assertSame($post->id, $generation->post_id);

        $this->actingAs($this->owner)->post(route('ai-image-generations.use', $generation), ['variant_ids' => $generation->variants->pluck('id')->all()]);

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
        $this->assertCount(1, $post->fresh()->assets);
    }

    public function test_a_post_from_another_client_cannot_be_targeted(): void
    {
        $other = Post::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('clients.ai.images.store', $this->client), $this->payload(['post_id' => $other->id]))
            ->assertNotFound();
    }

    public function test_designer_can_view_and_download_but_not_generate(): void
    {
        $generation = $this->generateSet(['variant_count' => 1]);
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $variant = $generation->variants->first();

        $this->actingAs($designer)->getJson(route('ai-image-generations.show', $generation))->assertOk();
        $this->actingAs($designer)->get(route('ai-image-variants.download', $variant))->assertOk();
        $this->actingAs($designer)->post(route('ai-post-ideas.images.store', $this->idea), $this->payload())->assertForbidden();
        $this->actingAs($designer)->post(route('ai-image-variants.refine', $variant), ['instruction' => 'Brighter', 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($designer)->post(route('ai-image-generations.use', $generation), ['variant_ids' => [$variant->id]])->assertForbidden();
    }

    public function test_designer_sees_the_studio_read_only_in_the_post_editor(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('imageStudio.canGenerate', false));
    }

    public function test_client_reviewer_and_viewer_are_denied_on_every_endpoint(): void
    {
        $generation = $this->generateSet(['variant_count' => 1]);
        $variant = $generation->variants->first();

        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        foreach ([$reviewer, $viewer] as $user) {
            $this->actingAs($user)->post(route('ai-post-ideas.images.store', $this->idea), $this->payload())->assertForbidden();
            $this->actingAs($user)->post(route('clients.ai.images.store', $this->client), $this->payload())->assertForbidden();
            $this->actingAs($user)->getJson(route('ai-image-generations.show', $generation))->assertForbidden();
            $this->actingAs($user)->post(route('ai-image-generations.use', $generation), ['variant_ids' => [$variant->id]])->assertForbidden();
            $this->actingAs($user)->get(route('ai-image-variants.download', $variant))->assertForbidden();

            foreach (['refine', 'regenerate', 'finalize'] as $action) {
                $this->actingAs($user)
                    ->post(route("ai-image-variants.{$action}", $variant), ['instruction' => 'x y', 'request_id' => (string) Str::uuid()])
                    ->assertForbidden();
            }

            $this->actingAs($user)->post(route('ai-image-variants.discard', $variant), ['discarded' => true])->assertForbidden();
        }
    }

    public function test_another_organisation_cannot_touch_a_set(): void
    {
        $generation = $this->generateSet(['variant_count' => 1]);
        $stranger = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        $this->actingAs($stranger)->getJson(route('ai-image-generations.show', $generation))->assertForbidden();
        $this->actingAs($stranger)->post(route('ai-post-ideas.images.store', $this->idea), $this->payload())->assertForbidden();
    }

    public function test_every_endpoint_is_not_found_when_ai_is_disabled(): void
    {
        $generation = $this->generateSet(['variant_count' => 1]);
        Config::set('ai.enabled', false);

        $this->actingAs($this->owner)->post(route('ai-post-ideas.images.store', $this->idea), $this->payload())->assertNotFound();
        $this->actingAs($this->owner)->getJson(route('ai-image-generations.show', $generation))->assertNotFound();
    }

    public function test_studio_props_carry_no_brief_internals_costs_or_keys(): void
    {
        Config::set('ai.images.gemini.api_key', 'gemini-secret-should-not-leak');
        $generation = $this->generateSet(['variant_count' => 1]);

        $response = $this->actingAs($this->owner)->get(route('ai-post-ideas.show', $this->idea));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('imageStudio.canGenerate', true)
            ->where('imageStudio.defaultFormat', '4:5')
            ->has('imageStudio.generations', 1)
            ->has('imageStudio.generations.0.variants', 1)
            ->missing('imageStudio.generations.0.brief')
            ->missing('imageStudio.generations.0.cost_estimate')
            ->missing('imageStudio.generations.0.provider')
            ->missing('imageStudio.generations.0.artDirection.avoid')
        );

        $response->assertDontSee('gemini-secret-should-not-leak');
        $response->assertDontSee($generation->brief_hash);
        $response->assertDontSee('fake-draft');
    }

    public function test_the_lean_status_endpoint_returns_the_set(): void
    {
        $generation = $this->generateSet(['variant_count' => 2]);

        $this->actingAs($this->owner)
            ->getJson(route('ai-image-generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('generation.id', $generation->id)
            ->assertJsonPath('generation.settled', true)
            ->assertJsonCount(2, 'generation.variants')
            ->assertJsonMissingPath('generation.brief');
    }

    public function test_discard_hides_and_restores_a_tile(): void
    {
        $generation = $this->generateSet(['variant_count' => 1]);
        $variant = $generation->variants->first();

        $this->actingAs($this->owner)->post(route('ai-image-variants.discard', $variant), ['discarded' => true]);
        $this->assertNotNull($variant->fresh()->discarded_at);

        $this->actingAs($this->owner)->post(route('ai-image-variants.discard', $variant), ['discarded' => false]);
        $this->assertNull($variant->fresh()->discarded_at);
    }

    public function test_headline_is_required_when_headline_text_is_on(): void
    {
        $this->actingAs($this->owner)
            ->post(route('ai-post-ideas.images.store', $this->idea), $this->payload(['with_headline' => true]))
            ->assertSessionHasErrors('headline');
    }
}
