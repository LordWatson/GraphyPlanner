<?php

namespace Tests\Unit\Contracts;

use App\Contracts\ImageProvider;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageQualityTier;
use App\Services\AI\Images\GeminiImageProvider;
use App\Services\AI\Images\NullImageProvider;
use App\Services\AI\Images\OpenAiImageProvider;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageRenderOptions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 16.1: the ImageProvider binding matrix (enabled × key × provider) and the Null provider.
 */
class ImageProviderTest extends TestCase
{
    /**
     * @return array<string, array{bool, string, string|null, class-string}>
     */
    public static function bindingMatrix(): array
    {
        return [
            'disabled, gemini, key' => [false, 'gemini', 'key', NullImageProvider::class],
            'disabled, gemini, no key' => [false, 'gemini', null, NullImageProvider::class],
            'enabled, gemini, no key' => [true, 'gemini', null, NullImageProvider::class],
            'enabled, gemini, key' => [true, 'gemini', 'key', GeminiImageProvider::class],
            'enabled, openai, key' => [true, 'openai', 'key', OpenAiImageProvider::class],
            'enabled, openai, no key' => [true, 'openai', null, NullImageProvider::class],
            'enabled, null provider, key' => [true, 'null', 'key', NullImageProvider::class],
        ];
    }

    #[DataProvider('bindingMatrix')]
    public function test_binding_matrix(bool $enabled, string $provider, ?string $key, string $expected): void
    {
        Config::set('ai.enabled', $enabled);
        Config::set('ai.images.provider', $provider);
        Config::set('ai.images.gemini.api_key', $provider === 'gemini' ? $key : null);
        Config::set('ai.images.openai.api_key', $provider === 'openai' ? $key : null);

        $this->assertInstanceOf($expected, $this->app->make(ImageProvider::class));
    }

    public function test_null_provider_returns_an_image_of_the_requested_ratio(): void
    {
        $provider = new NullImageProvider;

        foreach (ImageAspectRatio::cases() as $ratio) {
            $image = $provider->generate(
                new AiImageBrief(subject: 'Test', palette: [['name' => 'Teal', 'hex' => '#205557']]),
                new ImageRenderOptions(aspectRatio: $ratio),
            );

            [$width, $height] = $ratio->dimensions(1024);
            $size = getimagesizefromstring($image->bytes);

            $this->assertSame('image/png', $image->mimeType);
            $this->assertSame([$width, $height], [$image->width, $image->height]);
            $this->assertSame([$width, $height], [$size[0], $size[1]]);
            $this->assertSame(0.0, $image->costEstimate);
        }
    }

    public function test_null_provider_finals_are_larger_and_deterministic(): void
    {
        $provider = new NullImageProvider;
        $brief = new AiImageBrief(subject: 'Test');
        $options = new ImageRenderOptions(tier: ImageQualityTier::Final, aspectRatio: ImageAspectRatio::Square_1_1, seed: 3);

        $first = $provider->generate($brief, $options);
        $second = $provider->generate($brief, $options);

        $this->assertSame(2048, $first->width);
        $this->assertSame($first->bytes, $second->bytes);
    }
}
