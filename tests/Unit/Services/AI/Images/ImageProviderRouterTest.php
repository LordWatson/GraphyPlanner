<?php

namespace Tests\Unit\Services\AI\Images;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageQualityTier;
use App\Exceptions\AI\ImageSafetyBlockedException;
use App\Services\AI\Images\ImageProviderRouter;
use App\Services\AI\Images\NullImageProvider;
use App\Services\AI\Images\OpenAiImageProvider;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageRenderOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Step 16.12: routing headline briefs to GPT Image, and the OpenAI adapter's request shape.
 */
class ImageProviderRouterTest extends TestCase
{
    private function router(): ImageProviderRouter
    {
        return new ImageProviderRouter(new NullImageProvider, $this->app);
    }

    private function configureOpenAi(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.images.text_provider', 'openai');
        Config::set('ai.images.openai.api_key', 'openai-key-never-logged');
        Config::set('ai.images.openai.base_url', 'https://openai.test/v1');
    }

    public function test_briefs_without_a_headline_use_the_default_provider(): void
    {
        $this->configureOpenAi();

        $this->assertInstanceOf(NullImageProvider::class, $this->router()->for(new AiImageBrief(subject: 'x')));
    }

    public function test_headline_briefs_use_openai_when_configured(): void
    {
        $this->configureOpenAi();
        Log::spy();

        $provider = $this->router()->for(new AiImageBrief(subject: 'x', textOverlay: 'Sale'), ['generation_id' => 5]);

        $this->assertInstanceOf(OpenAiImageProvider::class, $provider);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Image provider routed' && $context['generation_id'] === 5 && $context['provider'] === 'openai');
    }

    public function test_headline_briefs_fall_back_without_a_key_or_text_provider(): void
    {
        $this->configureOpenAi();
        Config::set('ai.images.openai.api_key', null);
        $this->assertInstanceOf(NullImageProvider::class, $this->router()->for(new AiImageBrief(subject: 'x', textOverlay: 'Sale')));

        $this->configureOpenAi();
        Config::set('ai.images.text_provider', null);
        $this->assertInstanceOf(NullImageProvider::class, $this->router()->for(new AiImageBrief(subject: 'x', textOverlay: 'Sale')));

        $this->configureOpenAi();
        Config::set('ai.enabled', false);
        $this->assertInstanceOf(NullImageProvider::class, $this->router()->for(new AiImageBrief(subject: 'x', textOverlay: 'Sale')));
    }

    public function test_openai_adapter_request_shape(): void
    {
        $this->configureOpenAi();
        $image = imagecreatetruecolor(4, 6);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Http::fake(['openai.test/*' => Http::response(['data' => [['b64_json' => base64_encode($png)]]])]);

        $result = (new OpenAiImageProvider)->generate(
            new AiImageBrief(subject: 'A sale banner', textOverlay: 'Sale'),
            new ImageRenderOptions(tier: ImageQualityTier::Final, aspectRatio: ImageAspectRatio::Portrait_4_5),
        );

        $this->assertSame([4, 6], [$result->width, $result->height]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/images/generations')
            && $request['model'] === 'gpt-image-2'
            && $request['size'] === '1024x1536'
            && $request['quality'] === 'high'
            && str_contains($request['prompt'], '"Sale"'));
    }

    public function test_openai_moderation_maps_to_safety_blocked(): void
    {
        $this->configureOpenAi();
        Sleep::fake();
        Http::fake(['openai.test/*' => Http::response(['error' => ['code' => 'moderation_blocked']], 400)]);

        $this->expectException(ImageSafetyBlockedException::class);

        (new OpenAiImageProvider)->generate(new AiImageBrief(subject: 'x'), new ImageRenderOptions);
    }
}
