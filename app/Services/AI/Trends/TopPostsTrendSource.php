<?php

namespace App\Services\AI\Trends;

use App\Contracts\TrendSource;
use App\Models\Client;
use App\Services\Analytics\EngagementMetrics;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Step M-7.5 (dev plan I-9.5) — a real-signal trend source grounded in the client's own
 * best-performing posts (from the M-3.1 engagement layer), not model guesswork. Each top post
 * becomes a "do more of this" insight tagged "Your top posts" with full confidence. Returns nothing
 * when the client has no measured engagement yet, so a new client simply falls back to the model
 * hypotheses.
 */
class TopPostsTrendSource implements TrendSource
{
    private const LOOKBACK_DAYS = 90;

    public function __construct(private readonly EngagementMetrics $engagement) {}

    public function label(): string
    {
        return 'Your top posts';
    }

    public function discover(Client $client, array $platforms, int $limit): AiTrends
    {
        $organization = $client->organization;
        $timezone = $organization !== null && in_array($organization->default_timezone, timezone_identifiers_list(), true)
            ? $organization->default_timezone
            : 'UTC';

        $to = CarbonImmutable::now($timezone);
        $range = new AnalyticsRange('custom', $to->subDays(self::LOOKBACK_DAYS), $to, $timezone);
        $scope = new AnalyticsScope([$client->org_id], [$client->id]);

        $channels = $this->engagement->channels($scope, $range);
        $topPosts = $this->engagement->topPosts($channels, $limit);

        $trends = [];

        foreach ($topPosts as $post) {
            $caption = is_string($post['caption'] ?? null) ? trim($post['caption']) : '';

            if ($caption === '') {
                continue;
            }

            $engagement = $post['engagement'] ?? null;
            $trends[] = new AiTrend(
                title: Str::limit($caption, 60),
                summary: $engagement !== null
                    ? "One of your best posts lately ({$engagement} engagements). Build on what worked — the format, hook and timing."
                    : 'One of your best-performing posts lately. Build on what worked — the format, hook and timing.',
                platforms: array_values(array_map('strval', $post['platforms'] ?? [])),
                confidence: 1.0,
                source: $this->label(),
            );
        }

        return new AiTrends($trends);
    }
}
