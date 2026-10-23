<?php

namespace Tests\Unit\Support\AI\Images;

use App\Enums\BusinessImagePreset;
use App\Enums\ImageAspectRatio;
use App\Models\Client;
use App\Support\AI\Images\BusinessImagePresetComposer;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessImagePresetComposerTest extends TestCase
{
    private function brand(string $name = 'Jess Bakes'): Client
    {
        // No DB needed — the composer only reads the brand's name.
        $client = new Client;
        $client->name = $name;

        return $client;
    }

    /**
     * The acceptance criterion: every preset produces a valid image generation request.
     */
    public function test_every_preset_produces_a_valid_generation_request(): void
    {
        $composer = new BusinessImagePresetComposer;

        foreach (BusinessImagePreset::cases() as $preset) {
            $data = $composer->compose(
                $this->brand(),
                $preset,
                'Sourdough & sea-salt caramel buns',
                null,
                2,
                (string) Str::uuid(),
            );

            $this->assertTrue($data->withHeadline, "{$preset->value} should overlay a headline");
            $this->assertNotNull($data->headline);
            $this->assertNotSame('', trim((string) $data->headline));
            $this->assertNotEmpty($data->overrides['subject'] ?? '', "{$preset->value} must set a subject");
            $this->assertNotEmpty($data->overrides['composition'] ?? '', "{$preset->value} must set a composition");
            $this->assertSame(ImageAspectRatio::Square_1_1, $data->aspectRatio);
            $this->assertSame(2, $data->variantCount);
            $this->assertNull($data->idea);
            $this->assertNull($data->post);
        }
    }

    public function test_the_headline_is_clamped_to_eighty_characters(): void
    {
        $composer = new BusinessImagePresetComposer;
        $text = str_repeat('a', 120);

        $data = $composer->compose($this->brand(), BusinessImagePreset::QuoteCard, $text, null, 1, (string) Str::uuid());

        $this->assertLessThanOrEqual(80, mb_strlen((string) $data->headline));
    }

    public function test_the_variant_count_is_clamped_to_the_configured_maximum(): void
    {
        config(['ai.images.max_variants' => 4]);
        $composer = new BusinessImagePresetComposer;

        $data = $composer->compose($this->brand(), BusinessImagePreset::TodaysSpecial, 'Fresh', null, 99, (string) Str::uuid());

        $this->assertSame(4, $data->variantCount);
    }

    public function test_a_requested_aspect_ratio_overrides_the_preset_default(): void
    {
        $composer = new BusinessImagePresetComposer;

        $data = $composer->compose($this->brand(), BusinessImagePreset::TodaysSpecial, 'Fresh', ImageAspectRatio::Story_9_16, 1, (string) Str::uuid());

        $this->assertSame(ImageAspectRatio::Story_9_16, $data->aspectRatio);
    }

    public function test_the_photographic_preset_keeps_the_brand_style_but_the_cards_override_it(): void
    {
        $composer = new BusinessImagePresetComposer;
        $uuid = fn () => (string) Str::uuid();

        $special = $composer->compose($this->brand(), BusinessImagePreset::TodaysSpecial, 'Buns', null, 1, $uuid());
        $card = $composer->compose($this->brand(), BusinessImagePreset::OpeningHours, 'Mon–Sat 8–4', null, 1, $uuid());

        // Today's special is a photo, so it must not force a style (the brand's imagery style wins).
        $this->assertArrayNotHasKey('style', $special->overrides);
        // The typographic card overrides style so it is not rendered as a photograph.
        $this->assertArrayHasKey('style', $card->overrides);
        $this->assertStringContainsStringIgnoringCase('not a photograph', $card->overrides['style']);
    }

    public function test_the_subject_embeds_the_brand_name(): void
    {
        $composer = new BusinessImagePresetComposer;

        $data = $composer->compose($this->brand('Corner Bakehouse'), BusinessImagePreset::SaleAnnouncement, '20% off cakes', null, 1, (string) Str::uuid());

        $this->assertStringContainsString('Corner Bakehouse', $data->overrides['subject']);
        $this->assertStringContainsString('20% off cakes', $data->overrides['subject']);
    }
}
