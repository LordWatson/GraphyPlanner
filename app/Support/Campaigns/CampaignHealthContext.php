<?php

namespace App\Support\Campaigns;

use Carbon\CarbonImmutable;

/**
 * Step C-0.4 — the facts health rules primed for a batch of campaigns, keyed by rule fact name and
 * campaign id, plus "today" in the organization's timezone. Lives for one `computeMany()` call, so
 * rules themselves stay stateless.
 */
final class CampaignHealthContext
{
    /**
     * @var array<string, array<int, mixed>>
     */
    private array $facts = [];

    public function __construct(public readonly CarbonImmutable $today) {}

    /**
     * @param  array<int, mixed>  $valuesByCampaignId
     */
    public function put(string $fact, array $valuesByCampaignId): void
    {
        $this->facts[$fact] = $valuesByCampaignId;
    }

    public function get(string $fact, int $campaignId, mixed $default = null): mixed
    {
        return $this->facts[$fact][$campaignId] ?? $default;
    }

    public function has(string $fact): bool
    {
        return array_key_exists($fact, $this->facts);
    }
}
