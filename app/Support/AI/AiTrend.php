<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A single discovered trend for a client/platform set. `confidence` carries a hypothesis marker
 * (DeepSeek can't browse, so a model trend is a hypothesis, not a browsed fact). Step M-7.5 adds
 * `source` — a short human label of where the trend came from ("AI hypothesis", "Your top posts") —
 * so the UI is honest about what is model guesswork versus the brand's own real signal.
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
        public ?string $source = null,
    ) {}

    /**
     * Return a copy tagged with the given source label.
     */
    public function withSource(string $source): self
    {
        return new self($this->title, $this->summary, $this->platforms, $this->confidence, $source);
    }

    /**
     * @return array{title: string, summary: string, platforms: array<int, string>, confidence: float, source: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'platforms' => $this->platforms,
            'confidence' => $this->confidence,
            'source' => $this->source,
        ];
    }
}
