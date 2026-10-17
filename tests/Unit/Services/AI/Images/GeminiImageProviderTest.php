<?php

namespace Tests\Unit\Services\AI\Images;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageQualityTier;
use App\Enums\ReferenceImageRole;
use App\Exceptions\AI\ImageProviderException;
use App\Exceptions\AI\ImageProviderTimeoutException;
use App\Exceptions\AI\ImageProviderUnavailableException;
use App\Exceptions\AI\ImageSafetyBlockedException;
use App\Services\AI\Images\GeminiImageProvider;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Step 16.6: the Gemini adapter's request shape, error mapping, retry/backoff and logging.
 */
class GeminiImageProviderTest extends TestCase
{
    private const KEY = 'gemini-test-key-never-logged';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.images.gemini.api_key', self::KEY);
        Config::set('ai.images.gemini.base_url', 'https://gemini.test/v1beta');
        Sleep::fake();
    }

    private function brief(): AiImageBrief
    {
        return new AiImageBrief(
            subject: 'SECRET-PROMPT-SUBJECT a pumpkin pastry on a marble counter',
            mood: 'Warm',
            palette: [['name' => 'Teal', 'hex' => '#205557']],
            style: 'Natural light photography',
        );
    }

    private function pngBase64(): string
    {
        $image = imagecreatetruecolor(8, 10);
        ob_start();
        imagepng($image);

        return base64_encode((string) ob_get_clean());
    }

    private function imageResponse(): array
    {
        return [
            'responseId' => 'resp-123',
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => $this->pngBase64()]]]],
            ]],
        ];
    }

    public function test_draft_request_uses_the_draft_model_ratio_seed_and_reference_parts(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->imageResponse())]);

        $references = [
            new ReferenceImage('image/webp', 'logo-bytes', ReferenceImageRole::Logo),
            new ReferenceImage('image/webp', 'style-bytes', ReferenceImageRole::Style),
        ];

        $image = (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions(
            tier: ImageQualityTier::Draft,
            aspectRatio: ImageAspectRatio::Portrait_4_5,
            seed: 42,
            references: $references,
        ));

        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame(8, $image->width);
        $this->assertSame(10, $image->height);
        $this->assertSame('resp-123', $image->providerRequestId);
        $this->assertSame(0.067, $image->costEstimate);

        Http::assertSent(function (Request $request) {
            $parts = $request['contents'][0]['parts'];

            return str_contains($request->url(), '/models/gemini-3.1-flash-image:generateContent')
                && $request->header('x-goog-api-key')[0] === self::KEY
                && $request['generationConfig']['imageConfig']['aspectRatio'] === '4:5'
                && $request['generationConfig']['imageConfig']['imageSize'] === '1K'
                && $request['generationConfig']['seed'] === 42
                && $request['generationConfig']['responseModalities'] === ['IMAGE']
                && count($parts) === 3
                && isset($parts[0]['inlineData'], $parts[1]['inlineData'])
                && str_contains($parts[2]['text'], 'Image 1 is the brand logo')
                && str_contains($parts[2]['text'], 'Teal (#205557)');
        });
    }

    public function test_final_tier_uses_the_final_model_and_size(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->imageResponse())]);

        $image = (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions(
            tier: ImageQualityTier::Final,
            aspectRatio: ImageAspectRatio::Link_1_91_1,
        ));

        $this->assertSame('gemini-3-pro-image', $image->model);
        $this->assertSame(0.134, $image->costEstimate);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/models/gemini-3-pro-image:generateContent')
            && $request['generationConfig']['imageConfig']['imageSize'] === '2K'
            && $request['generationConfig']['imageConfig']['aspectRatio'] === '16:9');
    }

    public function test_edit_sends_the_source_image_and_the_instruction(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->imageResponse())]);

        (new GeminiImageProvider)->edit(
            new ReferenceImage('image/webp', 'source', ReferenceImageRole::Draft),
            'warmer light',
            new ImageRenderOptions,
        );

        Http::assertSent(fn (Request $request) => $request['contents'][0]['parts'][0]['inlineData']['data'] === base64_encode('source')
            && str_contains($request['contents'][0]['parts'][1]['text'], 'warmer light'));
    }

    public function test_a_safety_finish_reason_maps_to_safety_blocked(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['candidates' => [['finishReason' => 'IMAGE_SAFETY', 'content' => ['parts' => []]]]])]);

        $this->expectException(ImageSafetyBlockedException::class);

        (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);
    }

    public function test_a_blocked_prompt_maps_to_safety_blocked(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']])]);

        $this->expectException(ImageSafetyBlockedException::class);

        (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);
    }

    public function test_429_is_retried_with_backoff_then_succeeds(): void
    {
        Http::fakeSequence('gemini.test/*')
            ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
            ->push($this->imageResponse());

        $image = (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);

        $this->assertSame('resp-123', $image->providerRequestId);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_persistent_5xx_gives_up_after_two_retries_as_unavailable(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);

        try {
            (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);
            $this->fail('Expected ImageProviderUnavailableException');
        } catch (ImageProviderUnavailableException) {
            Http::assertSentCount(3);
            Sleep::assertSleptTimes(2);
        }
    }

    public function test_a_400_is_not_retried_and_maps_to_a_provider_error(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400)]);

        try {
            (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);
            $this->fail('Expected ImageProviderException');
        } catch (ImageProviderException $e) {
            $this->assertSame('provider_error', $e->errorCode()->value);
            Http::assertSentCount(1);
        }
    }

    public function test_a_timeout_maps_to_the_timeout_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 90000 milliseconds'));

        $this->expectException(ImageProviderTimeoutException::class);

        (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions);
    }

    public function test_logs_carry_ids_but_never_the_prompt_the_key_or_the_image(): void
    {
        Http::fake(['gemini.test/*' => Http::response($this->imageResponse())]);

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        (new GeminiImageProvider)->generate($this->brief(), new ImageRenderOptions(logContext: ['org_id' => 7, 'generation_id' => 9, 'variant_id' => 11]));

        $all = implode("\n", $logged);
        $this->assertStringContainsString('Gemini image request completed', $all);
        $this->assertStringContainsString('"generation_id":9', $all);
        $this->assertStringContainsString('"duration_ms"', $all);
        $this->assertStringNotContainsString('SECRET-PROMPT-SUBJECT', $all);
        $this->assertStringNotContainsString(self::KEY, $all);
        $this->assertStringNotContainsString(substr($this->pngBase64(), 0, 40), $all);
    }
}
