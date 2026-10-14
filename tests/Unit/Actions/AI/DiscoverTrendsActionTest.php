<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Contracts\AiProvider;
use App\Models\Client;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DiscoverTrendsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delegates_to_the_ai_provider_and_returns_its_trends(): void
    {
        $client = Client::factory()->create();
        $trends = new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: ['instagram'], confidence: 0.8)]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')
            ->once()
            ->withArgs(fn (Client $c, array $platforms) => $c->is($client) && $platforms === ['instagram'])
            ->andReturn($trends);

        $result = (new DiscoverTrendsAction($ai))($client, ['instagram']);

        $this->assertSame($trends, $result);
    }

    public function test_it_caches_the_result_per_client_and_platform_set(): void
    {
        $client = Client::factory()->create();
        $trends = new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: ['instagram'], confidence: 0.8)]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')->once()->andReturn($trends);

        $action = new DiscoverTrendsAction($ai);

        $first = $action($client, ['instagram']);
        $second = $action($client, ['instagram']);

        $this->assertSame($trends, $first);
        $this->assertSame($trends, $second);
    }

    public function test_the_same_platforms_in_a_different_order_reuse_the_cache(): void
    {
        $client = Client::factory()->create();
        $trends = new AiTrends([new AiTrend(title: 'Trend', summary: 'Summary', platforms: ['instagram'], confidence: 0.8)]);

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')->once()->andReturn($trends);

        $action = new DiscoverTrendsAction($ai);

        $action($client, ['instagram', 'tiktok']);
        $action($client, ['tiktok', 'instagram']);

        $this->assertTrue(true);
    }

    public function test_a_different_client_does_not_share_the_cache(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $ai = Mockery::mock(AiProvider::class);
        $ai->shouldReceive('discoverTrends')->twice()->andReturn(new AiTrends());

        $action = new DiscoverTrendsAction($ai);

        $action($clientA, ['instagram']);
        $action($clientB, ['instagram']);

        $this->assertTrue(true);
    }
}
