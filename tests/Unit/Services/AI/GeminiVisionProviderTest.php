<?php

namespace Tests\Unit\Services\AI;

use App\Models\Client;
use App\Services\AI\GeminiVisionProvider;
use App\Support\AI\VisionImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiVisionProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.vision.model' => 'gemini-2.5-flash',
            'ai.vision.api_key' => 'VISION-KEY',
            'ai.vision.base_url' => 'https://vision.test',
        ]);
    }

    private function image(): VisionImage
    {
        return new VisionImage('image/jpeg', 'rawbytes');
    }

    public function test_it_parses_caption_suggestions_from_the_vendor_response(): void
    {
        Http::fake(['*generateContent*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'suggestions' => [
                        ['caption' => 'Fresh sourdough, out of the oven.', 'hashtags' => ['sourdough', 'bakery'], 'altText' => 'A golden loaf.', 'firstComment' => null],
                        ['caption' => 'Warm from the oven.', 'hashtags' => ['freshbread']],
                    ],
                ])]]],
            ]],
        ], 200)]);

        $result = (new GeminiVisionProvider)->captionsFromImage(Client::factory()->create(), $this->image(), ['instagram']);

        $this->assertCount(2, $result->suggestions);
        $this->assertSame('Fresh sourdough, out of the oven.', $result->suggestions[0]->caption);
        $this->assertSame(['sourdough', 'bakery'], $result->suggestions[0]->hashtags);

        // The image rode in as an inline data part, with the system grounding and JSON mode set.
        Http::assertSent(function ($request) {
            $body = $request->data();
            $parts = $body['contents'][0]['parts'];

            return $request->hasHeader('x-goog-api-key', 'VISION-KEY')
                && str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && isset($parts[0]['inlineData']['data'])
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && isset($body['systemInstruction']);
        });
    }

    public function test_an_unsupported_media_type_never_calls_the_vendor(): void
    {
        Http::fake();

        $result = (new GeminiVisionProvider)->captionsFromImage(Client::factory()->create(), new VisionImage('video/mp4', 'x'), []);

        $this->assertSame([], $result->suggestions);
        Http::assertNothingSent();
    }

    public function test_a_vendor_rejection_returns_empty_suggestions(): void
    {
        Http::fake(['*generateContent*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $result = (new GeminiVisionProvider)->captionsFromImage(Client::factory()->create(), $this->image(), []);

        $this->assertSame([], $result->suggestions);
    }

    public function test_unparsable_json_returns_empty_suggestions(): void
    {
        Http::fake(['*generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]],
        ], 200)]);

        $result = (new GeminiVisionProvider)->captionsFromImage(Client::factory()->create(), $this->image(), []);

        $this->assertSame([], $result->suggestions);
    }
}
