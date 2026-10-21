<?php

namespace App\Support\Analytics\Engagement;

/**
 * Step M-3.1 — one channel's engagement as the vendor reported it. Every metric is nullable: a
 * missing field means the network didn't report it (not zero). `platformPostId` / `postUrl` are
 * kept on the target when the vendor tells us them.
 */
final readonly class PostMetricsReading
{
    public function __construct(
        public ?int $impressions = null,
        public ?int $reach = null,
        public ?int $views = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $saves = null,
        public ?int $clicks = null,
        public ?string $platformPostId = null,
        public ?string $postUrl = null,
    ) {}

    /**
     * Map a vendor `post_metrics` object. Networks name the same idea differently (TikTok
     * "favorites", X "retweets"/"replies", Pinterest "pin_clicks"/"outbound_clicks"), so each
     * metric takes the first key present.
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function fromVendor(array $metrics, ?string $platformPostId = null, ?string $postUrl = null): self
    {
        return new self(
            impressions: self::first($metrics, ['impressions', 'impression_count']),
            reach: self::first($metrics, ['reach', 'unique_reach']),
            views: self::first($metrics, ['views', 'video_views', 'plays', 'view_count']),
            likes: self::first($metrics, ['likes', 'like_count', 'reactions']),
            comments: self::first($metrics, ['comments', 'comment_count', 'replies']),
            shares: self::first($metrics, ['shares', 'share_count', 'retweets', 'reposts']),
            saves: self::first($metrics, ['saves', 'saved', 'favorites']),
            clicks: self::first($metrics, ['clicks', 'link_clicks', 'outbound_clicks', 'pin_clicks']),
            platformPostId: $platformPostId,
            postUrl: $postUrl,
        );
    }

    /**
     * Interactions: likes + comments + shares + saves, over the ones reported. Null when none were.
     */
    public function engagement(): ?int
    {
        $parts = array_filter([$this->likes, $this->comments, $this->shares, $this->saves], fn (?int $value) => $value !== null);

        return $parts === [] ? null : array_sum($parts);
    }

    public function isEmpty(): bool
    {
        return $this->engagement() === null
            && $this->impressions === null
            && $this->reach === null
            && $this->views === null
            && $this->clicks === null;
    }

    /**
     * @return array<string, int|null>
     */
    public function toMetricColumns(): array
    {
        return [
            'impressions' => $this->impressions,
            'reach' => $this->reach,
            'views' => $this->views,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'saves' => $this->saves,
            'clicks' => $this->clicks,
            'engagement' => $this->engagement(),
        ];
    }

    /**
     * The first numeric value among `$keys` (floats rounded, negatives clamped to 0), else null.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $keys
     */
    public static function first(array $metrics, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $metrics[$key] ?? null;

            if (is_numeric($value)) {
                return max(0, (int) round((float) $value));
            }
        }

        return null;
    }
}
