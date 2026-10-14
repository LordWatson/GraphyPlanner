<?php

namespace Tests\Unit\Services\AI;

use App\Enums\Platform;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\DeepSeekProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DeepSeekProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.deepseek.api_key', 'test-key');
        config()->set('ai.deepseek.model', 'deepseek-chat');
        config()->set('ai.deepseek.base_url', 'https://api.deepseek.com');
        config()->set('ai.deepseek.timeout', 5);
        config()->set('ai.deepseek.max_tokens', 400);
    }

    public function test_suggest_captions_happy_path(): void
    {
        $content = json_encode([
            'suggestions' => [
                [
                    'caption' => 'On-brand caption',
                    'hashtags' => ['#one', '#two'],
                    'altText' => 'Alt here',
                    'firstComment' => 'Comment here',
                ],
            ],
        ]);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ], 200),
        ]);

        $post = new Post();
        $provider = new DeepSeekProvider();
        $result = $provider->suggestCaptions($post);

        $this->assertCount(1, $result);
        $arr = $result->toArray()[0];
        $this->assertSame('On-brand caption', $arr['caption']);
        $this->assertSame(['#one', '#two'], $arr['hashtags']);
        $this->assertSame('Alt here', $arr['altText']);
        $this->assertSame('Comment here', $arr['firstComment']);
    }

    public function test_retry_on_5xx_then_success(): void
    {
        $content = json_encode(['suggestions' => [['caption' => 'A']]]);

        Http::fakeSequence()
            ->push('', 500)
            ->push([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ], 200);

        Log::spy();

        $provider = new DeepSeekProvider();
        $res = $provider->suggestCaptions(new Post());
        $this->assertCount(1, $res);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_non_json_content_returns_empty(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hello, this is not JSON']],
                ],
            ], 200),
        ]);

        $provider = new DeepSeekProvider();
        $res = $provider->suggestCaptions(new Post());
        $this->assertCount(0, $res);
    }

    public function test_ideas_and_trends_map_platforms(): void
    {
        // Ideas
        $ideasJson = json_encode([
            'ideas' => [
                ['title' => 'Behind the scenes', 'platforms' => ['instagram', 'linkedin'], 'tags' => ['bts']],
            ],
        ]);

        // Trends
        $trendsJson = json_encode([
            'trends' => [
                ['topic' => 'UGC', 'platforms' => ['tiktok'], 'confidence' => 0.9],
            ],
        ]);

        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $ideasJson]]]], 200)
            ->push(['choices' => [['message' => ['content' => $trendsJson]]]], 200);

        $provider = new DeepSeekProvider();
        $client = new Client();

        $ideas = $provider->recommendPosts($client);
        $this->assertCount(1, $ideas);
        $this->assertSame(['instagram', 'linkedin'], $ideas->toArray()[0]['platforms']);

        $trends = $provider->discoverTrends($client, [Platform::TikTok]);
        $this->assertCount(1, $trends);
        $this->assertSame(['tiktok'], $trends->toArray()[0]['platforms']);
    }
}
