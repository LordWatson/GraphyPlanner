<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Support\AI\AiTrends;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Wraps App\Contracts\AiProvider::discoverTrends() (Step 2.3), caching the result per
 * client/platform-set for a short TTL so repeated dashboard/panel loads don't spam the
 * vendor with near-identical requests.
 */
class DiscoverTrendsAction
{
    /**
     * Short enough that trends still feel fresh, long enough to avoid spamming the vendor.
     */
    private const CACHE_TTL_SECONDS = 2 * 60 * 60;

    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    /**
     * @param  array<int, string>  $platforms
     */
    public function __invoke(Client $client, array $platforms): AiTrends
    {
        $cacheKey = $this->cacheKey($client, $platforms);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($client, $platforms) {
            Log::info('AI trends requested', [
                'client_id' => $client->id,
                'org_id' => $client->org_id,
                'platforms' => $platforms,
            ]);

            return $this->ai->discoverTrends($client, $platforms);
        });
    }

    /**
     * Platforms are sorted before hashing so the same set of platforms in a different order
     * reuses the same cache entry instead of issuing a duplicate vendor call.
     *
     * @param  array<int, string>  $platforms
     */
    private function cacheKey(Client $client, array $platforms): string
    {
        $sorted = $platforms;
        sort($sorted);

        return sprintf('ai:trends:%d:%s', $client->id, implode(',', $sorted));
    }
}
