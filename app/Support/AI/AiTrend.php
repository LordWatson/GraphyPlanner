<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A single AI-discovered trend (Step 2.1) for a client/platform set, with a
 * confidence marker since DeepSeek may propose hypotheses rather than
 * verified, browsed facts (see Step 2.2 prompting notes).
 */
final readonly class AiTrend implements JsonSerializable
{
    /**
     * @param  array<int, string>  $platforms
     */
    public function __construct(
        public string $title,
        public string $summary,
        public array $platforms = [],
        public float $confidence = 0.0,
    ) {}

    /**
     * @return array{title: string, summary: string, platforms: array<int, string>, confidence: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'platforms' => $this->platforms,
            'confidence' => $this->confidence,
        ];
    }
}
