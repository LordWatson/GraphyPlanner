<?php

namespace App\Actions\Business;

use App\Models\Client;
use App\Models\MediaKit;
use App\Services\Analytics\EngagementMetrics;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Step B-8.4 — assemble a brand's media kit from data that is already public: name and bio, audience
 * by platform (follower counts), average engagement, and top **published** posts. Reused by the
 * owner's editor, the public share page and the branded PDF, so all three show the same thing.
 *
 * Privacy is the whole point of this Action: it reads only published channels and public follower
 * counts through the shared {@see EngagementMetrics} read layer, and never touches emails, internal
 * notes, draft/unpublished posts or any user data. "Past partners" (B-11.2) aren't built yet, so
 * that section is always empty here.
 */
class BuildMediaKitAction
{
    private const DAYS = 90;

    private const TOP_POSTS = 6;

    public function __construct(private readonly EngagementMetrics $engagement) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(MediaKit $kit, Client $brand): array
    {
        $timezone = $brand->organization->default_timezone ?? 'UTC';
        $scope = new AnalyticsScope(orgIds: [$brand->org_id], clientIds: [$brand->id]);
        $end = CarbonImmutable::now($timezone);
        $range = AnalyticsRange::fromArray([
            'range' => 'custom',
            'from' => $end->subDays(self::DAYS - 1)->toDateString(),
            'to' => $end->toDateString(),
        ], $timezone);

        $summary = $this->engagement->summary($scope, $range, topPosts: self::TOP_POSTS, withBestTimes: false);

        return [
            'brand' => [
                'name' => $brand->name,
                'kind' => $brand->brand_kind?->label(),
                'bio' => $kit->bio,
                'location' => $brand->location,
                'website' => $brand->website,
            ],
            'audience' => [
                'total_followers' => $summary['followers']['current'] ?? null,
                'accounts' => array_map(fn (array $account) => [
                    'platform' => $account['platform'],
                    'platform_label' => $account['platform_label'],
                    'handle' => $account['handle'],
                    'followers' => $account['followers'],
                ], $summary['followers']['accounts'] ?? []),
            ],
            'engagement' => [
                'period_days' => self::DAYS,
                'measured_posts' => (int) ($summary['measured_channels'] ?? 0),
                'total_reach' => $summary['totals']['reach'] ?? null,
                'total_interactions' => $summary['totals']['engagement'] ?? null,
                'engagement_rate' => $summary['engagement_rate'] ?? null,
            ],
            'top_posts' => array_map(fn (array $post) => [
                'caption' => $post['caption'],
                'thumbnail_url' => $post['thumbnail_url'],
                'platforms' => $post['platforms'],
                'post_url' => $post['post_url'],
                'engagement' => $post['engagement'],
                'reach' => $post['reach'],
            ], $summary['top_posts'] ?? []),
            // B-11.2 (brand deals tracker) isn't built; past partners stay empty until it lands.
            'partners' => [],
            'generated_at' => $end->toIso8601String(),
        ];
    }

    /**
     * Find or create the brand's single media kit (never more than one — the client_id is unique).
     */
    public function kitFor(Client $brand): MediaKit
    {
        return MediaKit::query()->firstOrCreate(
            ['client_id' => $brand->id],
            ['org_id' => $brand->org_id],
        );
    }

    /**
     * A fresh, unguessable public slug for the share link.
     */
    public static function newToken(): string
    {
        return Str::lower(Str::random(40));
    }
}
