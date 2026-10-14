<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Models\AiTrend;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiTrends;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        private readonly AiGuardrailService $guardrail,
    ) {}

    /**
     * @param  array<int, string>  $platforms
     */
    public function __invoke(Client $client, array $platforms): AiTrends
    {
        $cacheKey = $this->cacheKey($client, $platforms);

        $cached = Cache::get($cacheKey);

        if ($cached instanceof AiTrends) {
            return $cached;
        }

        if ($cached !== null) {
            // A stale/incompatible value (e.g. __PHP_Incomplete_Class from a previous
            // version of the AiTrends DTO) was unserialized from the cache store.
            // Discard it and regenerate instead of letting it bubble up as a TypeError.
            Log::warning('Discarding invalid cached AI trends value', [
                'client_id' => $client->id,
                'org_id' => $client->org_id,
                'platforms' => $platforms,
            ]);

            Cache::forget($cacheKey);
        }

        $trends = $this->discover($client, $platforms);

        Cache::put($cacheKey, $trends, self::CACHE_TTL_SECONDS);

        return $trends;
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function discover(Client $client, array $platforms): AiTrends
    {
        Log::info('AI trends requested', [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'platforms' => $platforms,
        ]);

        $trends = $this->ai->discoverTrends($client, $platforms);

        $filtered = $this->guardrail->filterTrends($client->brandBrain, $trends, [
            'client_id' => $client->id,
            'org_id' => $client->org_id,
            'platforms' => $platforms,
        ]);

        $this->persist($client, $filtered);

        return $filtered;
    }

    /**
     * Upserts the discovered trends (keyed on client_id+title) so the Trends & Ideas panel
     * survives a page refresh instead of only existing in the request-scoped DTO/cache.
     */
    private function persist(Client $client, AiTrends $trends): void
    {
        if ($trends->trends === []) {
            return;
        }

        DB::transaction(function () use ($client, $trends) {
            foreach ($trends->trends as $trend) {
                AiTrend::query()->updateOrCreate(
                    ['client_id' => $client->id, 'title' => $trend->title],
                    [
                        'org_id' => $client->org_id,
                        'summary' => $trend->summary,
                        'platforms' => $trend->platforms,
                        'confidence' => $trend->confidence,
                    ],
                );
            }
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
