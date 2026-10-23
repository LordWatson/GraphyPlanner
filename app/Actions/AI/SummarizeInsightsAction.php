<?php

namespace App\Actions\AI;

use App\Actions\Business\GetBusinessInsightsAction;
use App\Contracts\InsightsSummarizer;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiTextUsage;
use App\Services\AI\DeepSeekProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.2 — turn a brand's Insights numbers into up to three plain-language takeaways shown on
 * the Insights page and in the weekly email digest. Generating costs a vendor call, so the result
 * is **cached once per brand per ISO week** (in the brand's timezone): the first viewer (or the
 * Monday digest) fills it, everyone else that week reads the cache. PII is scrubbed from every line
 * and the call is metered in `ai_usage_logs` (for the B-9.2 allowance).
 *
 * Never throws: a disabled/failed provider returns `available: false` with no takeaways, so the
 * page and the digest simply drop the section rather than erroring.
 */
class SummarizeInsightsAction
{
    public function __construct(
        private readonly InsightsSummarizer $provider,
        private readonly AiGuardrailService $guardrail,
        private readonly AiTextUsage $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $insights  the payload from {@see GetBusinessInsightsAction}
     * @return array{available: bool, generated_at: string|null, takeaways: list<array{text: string}>}
     */
    public function __invoke(Client $brand, array $insights, string $timezone, bool $force = false): array
    {
        $key = $this->key($brand, $timezone);

        if (! $force) {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                /** @var array{available: bool, generated_at: string|null, takeaways: list<array{text: string}>} $cached */
                return $cached;
            }
        }

        $result = $this->generate($brand, $insights, $timezone);

        // Only cache a produced summary — an empty one (AI off / failed) should be retried next view
        // rather than pinned for a whole week.
        if ($result['available']) {
            Cache::put($key, $result, $this->ttlSeconds($timezone));
        }

        return $result;
    }

    /**
     * Read this week's cached takeaways without ever generating them (used where a vendor call on
     * the request path isn't wanted).
     *
     * @return array{available: bool, generated_at: string|null, takeaways: list<array{text: string}>}|null
     */
    public function cached(Client $brand, string $timezone): ?array
    {
        $value = Cache::get($this->key($brand, $timezone));

        if (! is_array($value)) {
            return null;
        }

        /** @var array{available: bool, generated_at: string|null, takeaways: list<array{text: string}>} $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array{available: bool, generated_at: string|null, takeaways: list<array{text: string}>}
     */
    private function generate(Client $brand, array $insights, string $timezone): array
    {
        $facts = $this->facts($insights);
        $startedAt = microtime(true);
        $lines = $this->provider->summarizeInsights($brand, $facts);

        $deepSeek = $this->provider instanceof DeepSeekProvider;
        $this->usage->record(
            $brand,
            'summarizeInsights',
            $lines !== [],
            $startedAt,
            $deepSeek ? 'deepseek' : 'null',
            $deepSeek ? (string) config('ai.deepseek.model', 'deepseek-flash') : 'none',
            $deepSeek ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0.0,
        );

        $takeaways = [];

        foreach ($lines as $line) {
            $scrubbed = trim($this->guardrail->scrub($line));

            if ($scrubbed !== '') {
                $takeaways[] = ['text' => $scrubbed];
            }
        }

        Log::info('Insights takeaways generated', [
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'count' => count($takeaways),
            'available' => $takeaways !== [],
        ]);

        return [
            'available' => $takeaways !== [],
            'generated_at' => $takeaways !== [] ? CarbonImmutable::now()->toIso8601String() : null,
            'takeaways' => $takeaways,
        ];
    }

    /**
     * Compact, already-computed numbers for the prompt — never raw rows, never anything private.
     * Missing/zero values are dropped so the model is only ever given real figures to talk about.
     *
     * @param  array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    private function facts(array $insights): array
    {
        $engagement = is_array($insights['engagement'] ?? null) ? $insights['engagement'] : [];
        $totals = is_array($engagement['totals'] ?? null) ? $engagement['totals'] : [];
        $followers = is_array($engagement['followers'] ?? null) ? $engagement['followers'] : [];

        $facts = array_filter([
            'period_days' => $insights['windowDays'] ?? null,
            'posts_published_last_8_weeks' => $insights['publishedTotal'] ?? null,
            'current_streak_weeks' => $insights['streak'] ?? null,
            'weekly_goal' => $insights['goalPerWeek'] ?? null,
            'total_reach' => $totals['reach'] ?? null,
            'total_impressions' => $totals['impressions'] ?? null,
            'total_interactions' => $totals['engagement'] ?? null,
            'engagement_rate_percent' => $engagement['engagement_rate'] ?? null,
            'followers' => $followers['current'] ?? null,
            'follower_growth' => $followers['growth'] ?? null,
        ], fn ($value) => $value !== null && $value !== 0);

        $byPlatform = [];

        foreach ((array) ($engagement['by_platform'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['measured'] ?? 0) > 0) {
                $byPlatform[] = array_filter([
                    'platform' => $row['label'] ?? $row['platform'] ?? null,
                    'reach' => $row['reach'] ?? null,
                    'interactions' => $row['engagement'] ?? null,
                    'engagement_rate_percent' => $row['engagement_rate'] ?? null,
                ], fn ($value) => $value !== null);
            }
        }

        if ($byPlatform !== []) {
            $facts['by_platform'] = $byPlatform;
        }

        $topPosts = (array) ($engagement['top_posts'] ?? []);
        $topPost = (array) ($topPosts[0] ?? []);

        if (($topPost['caption'] ?? null) !== null) {
            $facts['top_post'] = array_filter([
                'caption' => $topPost['caption'],
                'interactions' => $topPost['engagement'] ?? null,
                'platforms' => $topPost['platforms'] ?? null,
            ], fn ($value) => $value !== null);
        }

        $bestTimes = (array) ($insights['bestTimes'] ?? []);
        $bestTime = (array) ($bestTimes[0] ?? []);

        if (($bestTime['source'] ?? null) === 'data' && isset($bestTime['slot']['label'])) {
            $facts['best_time_to_post'] = [
                'platform' => $bestTime['platform_label'] ?? $bestTime['platform'] ?? null,
                'when' => $bestTime['slot']['label'],
            ];
        }

        return $facts;
    }

    private function key(Client $brand, string $timezone): string
    {
        $week = CarbonImmutable::now($this->safeTimezone($timezone))->isoFormat('GGGG-[W]WW');

        return "business:insights-takeaways:{$brand->id}:{$week}";
    }

    private function ttlSeconds(string $timezone): int
    {
        $now = CarbonImmutable::now($this->safeTimezone($timezone));
        // A little past next Monday so "this week's" takeaways roll over cleanly.
        $expiry = $now->next(CarbonImmutable::MONDAY)->startOfDay()->addHours(2);

        return max(3600, (int) $now->diffInSeconds($expiry));
    }

    private function safeTimezone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
