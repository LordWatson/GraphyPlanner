<?php

namespace App\Support\Analytics\Benchmarks;

/**
 * Step GTM-4.1 — one public profile's figures as a source found them. Every field is nullable and
 * null means **"this platform didn't report it"**, never zero: a benchmark that invents a number
 * is worse than one that admits a gap.
 */
final readonly class CompetitorProfileReading
{
    public function __construct(
        public ?int $followers = null,
        public ?int $postCount = null,
        public ?int $avgEngagement = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->followers === null && $this->postCount === null && $this->avgEngagement === null;
    }

    /**
     * @return array<string, int> only the fields that were actually reported
     */
    public function toColumns(): array
    {
        return array_filter([
            'followers' => $this->followers,
            'post_count' => $this->postCount,
            'avg_engagement' => $this->avgEngagement,
        ], fn (?int $value) => $value !== null);
    }
}
