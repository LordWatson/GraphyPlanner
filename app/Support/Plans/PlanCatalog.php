<?php

namespace App\Support\Plans;

use App\Enums\OrganizationType;
use InvalidArgumentException;

/**
 * Step CG-2.1 — the registry over `config/plans.php`. Everything that needs a plan (the Organization
 * model, entitlements, the plan page, the pricing table, the webhook sync) goes through here rather
 * than reading the config array directly.
 */
class PlanCatalog
{
    /**
     * @return array<string, Plan>
     */
    public function all(): array
    {
        $plans = [];

        foreach ((array) config('plans.plans', []) as $key => $config) {
            $plans[(string) $key] = new Plan((string) $key, (array) $config);
        }

        return $plans;
    }

    public function find(string $key): ?Plan
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Resolve a plan, falling back to the default for the given workspace type if the key is unknown
     * or empty (so a never-set or stale `organizations.plan` always resolves to a real plan).
     */
    public function resolve(?string $key, OrganizationType $type): Plan
    {
        if ($key !== null && $key !== '') {
            $plan = $this->find($key);

            if ($plan !== null) {
                return $plan;
            }
        }

        return $this->get($this->defaultKeyFor($type));
    }

    public function get(string $key): Plan
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown plan [{$key}].");
    }

    public function defaultKeyFor(OrganizationType $type): string
    {
        $defaults = (array) config('plans.defaults', []);

        return (string) ($defaults[$type->value] ?? 'business_free');
    }

    /**
     * Plans shown on the public pricing table, in config order.
     *
     * @return list<Plan>
     */
    public function public(): array
    {
        return array_values(array_filter($this->all(), fn (Plan $plan) => $plan->isPublic()));
    }

    /**
     * Find the plan (and the currency/interval it was bought at) for a Stripe Price ID — used by the
     * webhook sync to map a subscription back onto `organizations.plan`.
     *
     * @return array{plan: Plan, currency: string, interval: string}|null
     */
    public function findByStripePriceId(string $priceId): ?array
    {
        foreach ($this->all() as $plan) {
            foreach (['gbp', 'usd'] as $currency) {
                foreach (['monthly', 'annual'] as $interval) {
                    if ($plan->stripePriceId($currency, $interval) === $priceId) {
                        return ['plan' => $plan, 'currency' => $currency, 'interval' => $interval];
                    }
                }
            }
        }

        return null;
    }
}
