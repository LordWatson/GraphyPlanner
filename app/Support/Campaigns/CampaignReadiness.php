<?php

namespace App\Support\Campaigns;

/**
 * Step C-4.5 — a campaign's readiness: a weighted percentage (null = "Not planned"), the blockers
 * in plain words, and how many deliverables sit in each state.
 */
final readonly class CampaignReadiness
{
    /**
     * @param  list<array{code: string, reason: string}>  $blockers
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public ?int $percent,
        public array $blockers = [],
        public array $counts = [],
    ) {}

    public static function notPlanned(): self
    {
        return new self(null);
    }

    public function isPlanned(): bool
    {
        return $this->percent !== null;
    }

    public function label(): string
    {
        return $this->percent === null ? 'Not planned' : "{$this->percent}% ready";
    }

    /**
     * @return array{percent: int|null, label: string, blockers: list<array{code: string, reason: string}>, counts: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'percent' => $this->percent,
            'label' => $this->label(),
            'blockers' => $this->blockers,
            'counts' => $this->counts,
        ];
    }
}
