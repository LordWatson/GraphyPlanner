<?php

namespace App\Services\Plans;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Organization;
use App\Support\Plans\Plan;
use App\Support\Plans\PlanCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-2.2 / B-9.1 — reads a plan's caps and gates from `config/plans.php` (via {@see PlanCatalog})
 * and enforces them. The org's effective plan is `organizations.plan`, falling back to the default
 * for its workspace type, so an agency org (whose plan is unlimited by default) is never capped.
 */
class ConfigEntitlements implements Entitlements
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function plan(Organization $organization): Plan
    {
        return $this->catalog->resolve($organization->plan, $organization->type);
    }

    public function limit(Organization $organization, Feature $feature): ?int
    {
        return $this->plan($organization)->limit($feature);
    }

    public function allows(Organization $organization, Feature $feature): bool
    {
        return $this->plan($organization)->allows($feature);
    }

    public function remaining(Organization $organization, Feature $feature, int $used): ?int
    {
        $limit = $this->limit($organization, $feature);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $used);
    }

    public function ensure(Organization $organization, Feature $feature, int $current = 0): void
    {
        $plan = $this->plan($organization);

        if ($feature->isGate()) {
            if ($plan->allows($feature)) {
                return;
            }

            $this->deny($organization, $plan, $feature, null, $current);
        }

        $limit = $plan->limit($feature);

        if ($limit !== null && $current >= $limit) {
            $this->deny($organization, $plan, $feature, $limit, $current);
        }
    }

    private function deny(Organization $organization, Plan $plan, Feature $feature, ?int $limit, int $current): never
    {
        Log::warning('Plan limit reached', [
            'org_id' => $organization->id,
            'plan' => $plan->key,
            'feature' => $feature->value,
            'limit' => $limit,
            'current' => $current,
        ]);

        throw new PlanLimitReachedException(
            feature: $feature,
            currentPlan: $plan,
            limit: $limit,
            current: $current,
            upgradeTo: $this->suggestUpgrade($plan, $feature),
        );
    }

    /**
     * The cheapest public plan that unblocks this feature — the first one in config order that grants
     * a gate, or raises a limit above the current plan's cap. Returns `null` if nothing does (then the
     * prompt just explains the cap without naming a plan).
     */
    private function suggestUpgrade(Plan $current, Feature $feature): ?Plan
    {
        foreach ($this->catalog->public() as $plan) {
            if ($plan->key === $current->key || $plan->isAgency()) {
                continue;
            }

            if ($feature->isGate()) {
                if ($plan->allows($feature)) {
                    return $plan;
                }

                continue;
            }

            $planLimit = $plan->limit($feature);
            $currentLimit = $current->limit($feature);

            if ($planLimit === null || ($currentLimit !== null && $planLimit > $currentLimit)) {
                return $plan;
            }
        }

        return null;
    }
}
