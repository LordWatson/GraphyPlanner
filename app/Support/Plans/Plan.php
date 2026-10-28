<?php

namespace App\Support\Plans;

use App\Enums\Feature;

/**
 * Step CG-2.1 — a read-only view over one plan's `config/plans.php` entry. Resolving a plan by key
 * (via {@see PlanCatalog}) gives the limits, gate features, prices and marketing copy without any
 * code reaching into the raw config array itself.
 */
class Plan
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly string $key,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->key);
    }

    public function segment(): string
    {
        return (string) ($this->config['segment'] ?? 'business');
    }

    public function tagline(): string
    {
        return (string) ($this->config['tagline'] ?? '');
    }

    public function isPublic(): bool
    {
        return (bool) ($this->config['public'] ?? false);
    }

    public function isAgency(): bool
    {
        return $this->segment() === 'agency';
    }

    public function trialDays(): int
    {
        return (int) ($this->config['trial_days'] ?? 0);
    }

    /**
     * The numeric cap for a limit feature, or `null` for unlimited. Returns `null` for a gate
     * feature (it has no cap — use {@see allows()}).
     */
    public function limit(Feature $feature): ?int
    {
        if ($feature->isGate()) {
            return null;
        }

        $value = $this->config['limits'][$feature->value] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function isUnlimited(Feature $feature): bool
    {
        return $feature->isLimit() && $this->limit($feature) === null;
    }

    /**
     * Whether the plan grants the feature at all: a gate's boolean, or a limit that is unlimited or
     * has a cap greater than zero.
     */
    public function allows(Feature $feature): bool
    {
        if ($feature->isGate()) {
            return (bool) ($this->config['features'][$feature->value] ?? false);
        }

        $limit = $this->limit($feature);

        return $limit === null || $limit > 0;
    }

    /**
     * The headline price (major currency units, e.g. 15 for £15) for a currency/interval, or `null`
     * if that combination isn't offered.
     */
    public function price(string $currency, string $interval): ?int
    {
        $amount = $this->config['prices'][strtolower($currency)][$interval]['amount'] ?? null;

        return $amount === null ? null : (int) $amount;
    }

    public function stripePriceId(string $currency, string $interval): ?string
    {
        $id = $this->config['prices'][strtolower($currency)][$interval]['stripe_price_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * A paid plan is one whose monthly price in any currency is above zero.
     */
    public function isPaid(): bool
    {
        foreach (array_keys((array) ($this->config['prices'] ?? [])) as $currency) {
            if (($this->price((string) $currency, 'monthly') ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    public function isFree(): bool
    {
        return ! $this->isPaid();
    }

    /**
     * @return list<string>
     */
    public function highlights(): array
    {
        return array_values(array_map('strval', (array) ($this->config['highlights'] ?? [])));
    }

    /**
     * The plan shaped for an Inertia prop (plan page, pricing table). Limits are rendered as the cap
     * or `null` for unlimited; gates as booleans.
     *
     * @param  list<string>  $currencies  Which currency codes (lowercase) to include.
     * @return array<string, mixed>
     */
    public function toArray(array $currencies = ['gbp', 'usd']): array
    {
        $limits = [];
        foreach (Feature::limits() as $feature) {
            $limits[$feature->value] = $this->limit($feature);
        }

        $features = [];
        foreach (Feature::gates() as $feature) {
            $features[$feature->value] = $this->allows($feature);
        }

        $prices = [];
        foreach ($currencies as $currency) {
            $prices[$currency] = [
                'monthly' => $this->price($currency, 'monthly'),
                'annual' => $this->price($currency, 'annual'),
            ];
        }

        return [
            'key' => $this->key,
            'name' => $this->name(),
            'segment' => $this->segment(),
            'tagline' => $this->tagline(),
            'trial_days' => $this->trialDays(),
            'is_free' => $this->isFree(),
            'prices' => $prices,
            'limits' => $limits,
            'features' => $features,
            'highlights' => $this->highlights(),
        ];
    }
}
