<?php

namespace App\Support\AI\Campaigns;

use JsonSerializable;

/**
 * Step C-3.3 — one planned slot the AI planner suggests. Becomes a `CampaignDeliverable` only when
 * a person ticks it and accepts.
 */
final readonly class AiDeliverableSuggestion implements JsonSerializable
{
    /**
     * @param  list<string>  $flags  banned phrases spotted by the guardrail
     * @param  list<string>  $warnings  e.g. "Instagram already has 2 posts on 3 Nov."
     */
    public function __construct(
        public string $title,
        public string $platform,
        public ?string $format,
        public ?string $pillar,
        public ?int $phaseId,
        public string $date,
        public ?string $time,
        public ?string $angle,
        public array $flags = [],
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $warnings
     */
    public function with(array $flags, array $warnings): self
    {
        return new self($this->title, $this->platform, $this->format, $this->pillar, $this->phaseId, $this->date, $this->time, $this->angle, $flags, $warnings);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'platform' => $this->platform,
            'format' => $this->format,
            'pillar' => $this->pillar,
            'phase_id' => $this->phaseId,
            'planned_for' => $this->date,
            'planned_time' => $this->time,
            'angle' => $this->angle,
            'flags' => $this->flags,
            'warnings' => $this->warnings,
        ];
    }
}
