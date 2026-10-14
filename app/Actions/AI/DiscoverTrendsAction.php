<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Enums\Platform;
use App\Models\Client;
use App\Support\AI\AiTrends;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

class DiscoverTrendsAction
{
    public function __construct(
        protected AiProvider $ai,
        protected CacheRepository $cache,
    ) {}

    /**
     * @param array<int, Platform> $platforms
     */
    public function __invoke(Client $client, array $platforms): AiTrends
    {
        // Sort platforms for a stable cache key regardless of input order
        $platformKeys = array_map(fn (Platform $p) => $p->value, $platforms);
        sort($platformKeys);
        $cacheKey = sprintf('ai:trends:%d:%s', $client->id, implode(',', $platformKeys));

        $ttlSeconds = 2 * 60 * 60; // 2 hours

        return $this->cache->remember($cacheKey, $ttlSeconds, function () use ($client, $platforms) {
            Log::info('AI DiscoverTrendsAction cache miss — fetching', [
                'client_id' => $client->id,
                'platforms' => array_map(fn (Platform $p) => $p->value, $platforms),
            ]);

            return $this->ai->discoverTrends($client, $platforms);
        });
    }
}
