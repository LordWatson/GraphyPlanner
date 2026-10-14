<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A collection of AiTrend items returned by AiProvider::discoverTrends()
 * (Step 2.1).
 */
final readonly class AiTrends implements JsonSerializable
{
    /**
     * @param  array<int, AiTrend>  $trends
     */
    public function __construct(
        public array $trends = [],
    ) {}

    /**
     * @return array<int, array{title: string, summary: string, platforms: array<int, string>, confidence: float}>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn (AiTrend $trend): array => $trend->jsonSerialize(),
            $this->trends,
        );
    }
}
