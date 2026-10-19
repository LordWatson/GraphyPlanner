<?php

namespace Tests\Unit\Support\AI\Images;

use App\Enums\AssetSource;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageQualityTier;
use App\Enums\Platform;
use App\Enums\ReferenceImageRole;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImagePromptRenderer;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Tests\TestCase;

/**
 * Step 16.1: DTO construction/serialisation, the aspect-ratio enum and the prompt renderer.
 */
class ImageDtoTest extends TestCase
{
    public function test_brief_round_trips_through_an_array(): void
    {
        $brief = new AiImageBrief(
            subject: 'A pastry',
            composition: 'Close-up',
            mood: 'Warm',
            palette: [['name' => 'Teal', 'hex' => '#205557']],
            style: 'Photo',
            textOverlay: 'Autumn is here',
            avoid: ['clutter'],
            referenceAssetIds: [3, 5],
        );

        $this->assertEquals($brief, AiImageBrief::fromArray($brief->toArray()));
        $this->assertSame($brief->toArray(), $brief->jsonSerialize());
    }

    public function test_brief_from_array_coerces_odd_input(): void
    {
        $brief = AiImageBrief::fromArray([
            'subject' => '  A pastry  ',
            'palette' => ['Teal #205557', '#fff', 'Sand', ['name' => 'Lime', 'hex' => 'd2e842'], 42],
            'textOverlay' => '   ',
            'avoid' => ['clutter', '', null],
            'referenceAssetIds' => ['4', 'x'],
            'unknown' => 'ignored',
        ]);

        $this->assertSame('A pastry', $brief->subject);
        $this->assertSame([
            ['name' => 'Teal', 'hex' => '#205557'],
            ['name' => '#FFF', 'hex' => '#FFFFFF'],
            ['name' => 'Sand', 'hex' => null],
            ['name' => 'Lime', 'hex' => '#D2E842'],
        ], $brief->palette);
        $this->assertNull($brief->textOverlay);
        $this->assertSame(['clutter'], $brief->avoid);
        $this->assertSame([4], $brief->referenceAssetIds);
    }

    public function test_render_options_and_generated_image_defaults(): void
    {
        $options = new ImageRenderOptions;
        $this->assertSame(ImageQualityTier::Draft, $options->tier);
        $this->assertSame(ImageAspectRatio::Square_1_1, $options->aspectRatio);
        $this->assertNull($options->seed);

        $image = new GeneratedImage('image/jpeg', 'x', 1, 1, 'gemini', 'model');
        $this->assertSame('jpg', $image->extension());
        $this->assertSame(0.0, $image->costEstimate);

        $reference = new ReferenceImage('image/webp', 'x', ReferenceImageRole::Logo);
        $this->assertSame(ReferenceImageRole::Logo, $reference->role);
    }

    public function test_aspect_ratio_defaults_for_every_platform(): void
    {
        $expected = [
            Platform::Instagram->value => ImageAspectRatio::Portrait_4_5,
            Platform::TikTok->value => ImageAspectRatio::Story_9_16,
            Platform::Facebook->value => ImageAspectRatio::Square_1_1,
            Platform::LinkedIn->value => ImageAspectRatio::Square_1_1,
            Platform::YouTube->value => ImageAspectRatio::Story_9_16,
            Platform::GoogleBusiness->value => ImageAspectRatio::Portrait_4_5,
            Platform::Threads->value => ImageAspectRatio::Portrait_4_5,
            Platform::Pinterest->value => ImageAspectRatio::Story_9_16,
            Platform::X->value => ImageAspectRatio::Landscape_16_9,
        ];

        foreach (Platform::cases() as $platform) {
            $this->assertSame($expected[$platform->value], ImageAspectRatio::forPlatform($platform), $platform->value);
        }
    }

    public function test_aspect_ratio_dimensions_and_provider_ratio(): void
    {
        $this->assertSame([819, 1024], ImageAspectRatio::Portrait_4_5->dimensions());
        $this->assertSame([1024, 576], ImageAspectRatio::Landscape_16_9->dimensions());
        $this->assertSame('16:9', ImageAspectRatio::Link_1_91_1->providerRatio());
        $this->assertSame('9:16', ImageAspectRatio::Story_9_16->providerRatio());
    }

    public function test_asset_source_ai_has_a_label(): void
    {
        $this->assertSame('AI generated', AssetSource::Ai->label());
    }

    public function test_prompt_renderer_orders_sections_and_forbids_text_by_default(): void
    {
        $prompt = (new ImagePromptRenderer)->render(
            new AiImageBrief(subject: 'A pastry', composition: 'Close-up', mood: 'Warm', style: 'Photo', avoid: ['clutter']),
            [new ReferenceImage('image/webp', 'x', ReferenceImageRole::Logo)],
        );

        $this->assertStringStartsWith('Image 1 is the brand logo', $prompt);
        $this->assertLessThan(strpos($prompt, 'Style:'), strpos($prompt, 'Subject:'));
        $this->assertStringContainsString('no words, letters', $prompt);
        $this->assertStringContainsString('clutter', $prompt);
        $this->assertStringContainsString('real public figures', $prompt);

        $withText = (new ImagePromptRenderer)->render(new AiImageBrief(subject: 'A pastry', textOverlay: 'Autumn'));
        $this->assertStringContainsString('"Autumn"', $withText);
    }
}
