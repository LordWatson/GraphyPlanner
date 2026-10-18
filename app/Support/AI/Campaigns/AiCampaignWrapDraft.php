<?php

namespace App\Support\AI\Campaigns;

use JsonSerializable;

/**
 * Step C-7.3 — AI-suggested wrap report commentary (summary, highlights, learnings, next steps),
 * accepted per section. Never saved by itself.
 */
final readonly class AiCampaignWrapDraft implements JsonSerializable
{
    /**
     * @param  list<string>  $highlights
     * @param  array<string, list<string>>  $flags
     */
    public function __construct(
        public ?string $summary = null,
        public array $highlights = [],
        public ?string $learnings = null,
        public ?string $nextSteps = null,
        public array $flags = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $text = fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;

        return new self(
            summary: $text($payload['summary'] ?? null),
            highlights: array_values(array_filter(array_map(
                fn ($line) => is_scalar($line) ? trim((string) $line) : '',
                is_array($payload['highlights'] ?? null) ? $payload['highlights'] : [],
            ), fn (string $line) => $line !== '')),
            learnings: $text($payload['learnings'] ?? null),
            nextSteps: $text($payload['next_steps'] ?? null),
        );
    }

    /**
     * @param  array<string, list<string>>  $flags
     */
    public function withFlags(array $flags): self
    {
        return new self($this->summary, $this->highlights, $this->learnings, $this->nextSteps, $flags);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'fields' => [
                'summary' => $this->summary,
                'highlights' => $this->highlights,
                'learnings' => $this->learnings,
                'next_steps' => $this->nextSteps,
            ],
            'flags' => $this->flags,
        ];
    }
}
