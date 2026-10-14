<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Contracts\AiProvider;
use App\Enums\Platform;
use App\Models\Client;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Support\Facades\Cache;
use Mockery as m;
use Tests\TestCase;

class DiscoverTrendsActionTest extends TestCase
{
    public function test_it_caches_trends_per_client_and_platforms(): void
    {
        Cache::flush();

        $client = new Client(['id' => 1]);
        $trends = new AiTrends([new AiTrend('UGC', null, [Platform::TikTok], 0.9)]);

        $provider = m::mock(AiProvider::class);
        $provider->shouldReceive('discoverTrends')
            ->once()
            ->with($client, [Platform::TikTok])
            ->andReturn($trends);

        $action = new DiscoverTrendsAction($provider, app('cache.store'));

        $first = $action($client, [Platform::TikTok]);
        $second = $action($client, [Platform::TikTok]);

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
    }
}
