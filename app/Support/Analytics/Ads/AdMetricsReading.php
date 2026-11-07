<?php

namespace App\Support\Analytics\Ads;

/**
 * Step GTM-5.1 — one ad account's daily series as a source found it, the same shape as
 * `AccountMetricsReading`: keyed by `Y-m-d`, every figure nullable, and null meaning **"the
 * platform didn't report it"** rather than zero.
 *
 * No CPC or CPA here: they are spend ÷ clicks and spend ÷ conversions, derived where they are
 * shown so a stored ratio can never disagree with its own inputs.
 */
final readonly class AdMetricsReading
{
    /**
     * @param  array<string, array{spend_minor: int|null, impressions: int|null, clicks: int|null, conversions: int|null}>  $daily
     */
    public function __construct(
        public array $daily = [],
        public ?string $currency = null,
        public ?string $name = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->daily === [];
    }

    /**
     * One day's reported fields only, so an upsert never overwrites a figure with a null it was
     * never given.
     *
     * @return array<string, int>
     */
    public function columnsFor(string $date): array
    {
        return array_filter($this->daily[$date] ?? [], fn (?int $value) => $value !== null);
    }
}
