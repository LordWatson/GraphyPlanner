<?php

namespace App\Support\Analytics\Engagement;

/**
 * Step M-3.1 — one social account's figures right now (followers, profile views) plus any daily
 * series the network reports (reach / impressions per date). Nulls mean "not reported".
 */
final readonly class AccountMetricsReading
{
    /**
     * @param  array<string, array{reach: int|null, impressions: int|null}>  $daily  keyed by `Y-m-d`
     */
    public function __construct(
        public ?int $followers = null,
        public ?int $profileViews = null,
        public ?int $reach = null,
        public ?int $impressions = null,
        public array $daily = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data  the vendor's object for one platform
     */
    public static function fromVendor(array $data): self
    {
        $daily = [];

        foreach (['reach' => 'reach_timeseries', 'impressions' => 'impressions_timeseries'] as $metric => $key) {
            foreach ((array) ($data[$key] ?? []) as $point) {
                if (! is_array($point) || ! is_string($point['date'] ?? null) || ! is_numeric($point['value'] ?? null)) {
                    continue;
                }

                $date = substr($point['date'], 0, 10);

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    continue;
                }

                $daily[$date] ??= ['reach' => null, 'impressions' => null];
                $daily[$date][$metric] = max(0, (int) round((float) $point['value']));
            }
        }

        ksort($daily);

        return new self(
            followers: PostMetricsReading::first($data, ['followers', 'followers_count', 'subscribers']),
            profileViews: PostMetricsReading::first($data, ['profileViews', 'profile_views']),
            reach: PostMetricsReading::first($data, ['reach']),
            impressions: PostMetricsReading::first($data, ['impressions']),
            daily: $daily,
        );
    }

    public function isEmpty(): bool
    {
        return $this->followers === null && $this->profileViews === null && $this->reach === null
            && $this->impressions === null && $this->daily === [];
    }
}
