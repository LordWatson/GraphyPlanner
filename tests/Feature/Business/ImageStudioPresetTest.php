<?php

namespace Tests\Feature\Business;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Contracts\ImageProvider;
use App\Enums\ImageCapability;
use App\Enums\ImageGenerationStatus;
use App\Enums\ImageQualityTier;
use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\AiPostIdea;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step B-6.5 — the business shell's Image Studio presets end to end, with a fake ImageProvider
 * (the queue is `sync` in tests, so every job runs inline).
 */
class ImageStudioPresetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $brand;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
        Storage::fake('public');
        Storage::fake('local');

        $this->app->instance(ImageProvider::class, $this->fakeProvider());

        $this->org = Organization::factory()->business()->create();
        $this->brand = (new CreatePrimaryBrandAction)($this->org, 'Jess Bakes');
        $this->owner = User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner, 'email_verified_at' => now()]);

        RateLimiter::clear('ai-images:org:'.$this->org->id);
    }

    private function fakeProvider(): ImageProvider
    {
        return new class implements ImageProvider
        {
            public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage
            {
                return $this->render($options);
            }

            public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage
            {
                return $this->render($options);
            }

            private function render(ImageRenderOptions $options): GeneratedImage
            {
                $image = imagecreatetruecolor(40, 50);
                ob_start();
                imagepng($image);
                $bytes = (string) ob_get_clean();

                return new GeneratedImage('image/png', $bytes, 40, 50, 'fake', $this->model($options->tier), 'req', $this->costEstimate($options->tier));
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
                return 0.067;
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
            'preset' => 'todays_special',
            'text' => 'Sourdough & sea-salt caramel buns',
            'variant_count' => 2,
            'request_id' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    public function test_an_owner_generates_a_standalone_set_from_a_preset(): void
    {
        $this->actingAs($this->owner)
            ->post(route('business.studio.generate'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $generation = AiImageGeneration::query()->latest('id')->firstOrFail();

        $this->assertSame($this->brand->id, $generation->client_id);
        $this->assertNull($generation->ai_post_idea_id);
        $this->assertNull($generation->post_id);
        $this->assertTrue((bool) ($generation->art_direction['withHeadline'] ?? false));
        $this->assertSame('Sourdough & sea-salt caramel buns', $generation->art_direction['headline'] ?? null);
        $this->assertSame(ImageGenerationStatus::Ready, $generation->status);
        $this->assertCount(2, $generation->variants);
        $this->assertSame(2, Asset::query()->where('is_ai_generated', true)->count());
    }

    public function test_the_monthly_quota_is_enforced(): void
    {
        $this->org->update(['ai_monthly_image_quota' => 1]);

        $this->actingAs($this->owner)
            ->post(route('business.studio.generate'), $this->payload(['variant_count' => 2]))
            ->assertSessionHasErrors('imageStudio');

        $this->assertSame(0, AiImageGeneration::query()->count());
    }

    public function test_validation_rejects_a_missing_preset_or_text(): void
    {
        $this->actingAs($this->owner)
            ->post(route('business.studio.generate'), $this->payload(['text' => '']))
            ->assertSessionHasErrors('text');

        $this->actingAs($this->owner)
            ->post(route('business.studio.generate'), $this->payload(['preset' => 'not_a_preset']))
            ->assertSessionHasErrors('preset');
    }

    public function test_a_creator_can_view_the_studio_but_cannot_generate(): void
    {
        $creator = User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Designer, 'email_verified_at' => now()]);

        $this->actingAs($creator)
            ->get(route('business.studio.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business/studio')
                ->where('imageStudio.canGenerate', false)
                ->has('imageStudio.presets', 4));

        $this->actingAs($creator)
            ->post(route('business.studio.generate'), $this->payload())
            ->assertForbidden();
    }

    public function test_a_viewer_is_denied_on_the_studio(): void
    {
        $viewer = User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Viewer, 'email_verified_at' => now()]);

        $this->actingAs($viewer)->get(route('business.studio.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('business.studio.generate'), $this->payload())->assertForbidden();
    }

    public function test_the_index_lists_only_standalone_preset_sets(): void
    {
        // A standalone preset set …
        $this->actingAs($this->owner)->post(route('business.studio.generate'), $this->payload());
        // … and an idea-linked set, which belongs to the Ideas/post flow, not the studio page.
        AiImageGeneration::factory()->create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'post_id' => null,
            'ai_post_idea_id' => AiPostIdea::query()->create([
                'org_id' => $this->org->id,
                'client_id' => $this->brand->id,
                'title' => 'An idea',
                'platforms' => ['instagram'],
            ])->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('business.studio.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('imageStudio.generations', 1));
    }

    public function test_the_studio_is_not_found_when_ai_is_disabled(): void
    {
        Config::set('ai.enabled', false);

        $this->actingAs($this->owner)->get(route('business.studio.index'))->assertNotFound();
        $this->actingAs($this->owner)->post(route('business.studio.generate'), $this->payload())->assertNotFound();
    }
}
