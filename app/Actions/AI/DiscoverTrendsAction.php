<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Contracts\TrendSource;
use App\Models\AiTrend;
use App\Models\Client;
use App\Services\AI\AiCallMeter;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiUsageGuard;
use App\Services\AI\Trends\NullTrendSource;
use App\Support\AI\AiTrend as AiTrendDto;
use App\Support\AI\AiTrends;
use App\Support\AI\ClientContextBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wraps App\Contracts\AiProvider::discoverTrends() (Step 2.3), caching the result per
 * client/platform-set for a short TTL so repeated dashboard/panel loads don't spam the
 * vendor with near-identical requests. The key also carries a fingerprint of the client's
 * company context (Brand Brain, profile, campaigns) so editing the brand brain immediately
 * yields fresh, re-grounded trends instead of serving stale ones for the rest of the TTL.
 */
class DiscoverTrendsAction
{
    /**
     * Short enough that trends still feel fresh, long enough to avoid spamming the vendor.
     */
    private const CACHE_TTL_SECONDS = 2 * 60 * 60;

    /**
     * How each source labels DeepSeek's model trends (as opposed to live signal).
     */
    private const MODEL_SOURCE = 'AI hypothesis';

    public function __construct(
        private readonly AiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly AiUsageGuard $budget = new AiUsageGuard,
        private readonly AiCallMeter $meter = new AiCallMeter(new RecordAiUsageAction),
        private readonly TrendSource $signalSource = new NullTrendSource,
        private readonly ClientContextBuilder $context = new ClientContextBuilder,
    ) {}

    /**
     * Step M-7.5: the total trends to surface (model + real signal), lifting the old cap of 3.
     */
    private function cap(): int
    {
        return max(1, min(10, (int) config('ai.suggestions.per_request', 6)));
    }

    /**
     * @param  array<int, string>  $platforms
     */
    public function __invoke(Client $client, array $platforms): AiTrends
    {
        $cacheKey = $this->cacheKey($client, $platforms);

        $cached = Cache::get($cacheKey);

        if ($cached instanceof AiTrends) {
            // A cached result is free — no vendor call, so it neither meters nor counts against
            // the budget (Step M-7.1).
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

        // Step M-7.1: this only runs on a cache miss, i.e. a real vendor call — so budget-gate and
        // meter it here rather than in __invoke().
        $this->budget->ensureWithinBudget($client->organization);

        $cap = $this->cap();

        // Step M-7.5: the model's hypotheses, clearly labelled as such...
        $model = $this->ai->discoverTrends($client, $platforms);
        $this->meter->record($this->ai, $client, Auth::user(), 'discoverTrends', $model->trends !== []);
        $modelTrends = array_map(fn (AiTrendDto $trend) => $trend->withSource(self::MODEL_SOURCE), $model->trends);

        // ...plus the client's own real signal (top posts), which leads the list. No vendor call.
        $signalTrends = $this->signalSource->discover($client, $platforms, $cap)->trends;
        $signalTrends = array_slice($signalTrends, 0, intdiv($cap, 2));

        $merged = array_slice([...$signalTrends, ...$modelTrends], 0, $cap);

        $filtered = $this->guardrail->filterTrends($client->brandBrain, new AiTrends($merged), [
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
                        'source' => $trend->source,
                    ],
                );
            }
        });
    }

    /**
     * Platforms are sorted before hashing so the same set of platforms in a different order
     * reuses the same cache entry instead of issuing a duplicate vendor call; the context
     * fingerprint changes whenever the brand brain/campaigns do, retiring the old entry.
     *
     * @param  array<int, string>  $platforms
     */
    private function cacheKey(Client $client, array $platforms): string
    {
        $sorted = $platforms;
        sort($sorted);

        return sprintf('ai:trends:%d:%s:%s', $client->id, implode(',', $sorted), $this->context->fingerprint($client));
    }
}
