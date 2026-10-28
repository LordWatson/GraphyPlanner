<?php

namespace Tests\Unit\Plans;

use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Support\Plans\PlanCatalog;
use Tests\TestCase;

/**
 * Step CG-2.1 — every plan in config/plans.php must define every Feature (no silent omissions), and
 * agency plans must be unlimited with every gate on.
 */
class PlanDefinitionsTest extends TestCase
{
    public function test_every_plan_defines_every_feature(): void
    {
        $plans = (array) config('plans.plans');

        $this->assertNotEmpty($plans);

        foreach ($plans as $key => $definition) {
            foreach (Feature::limits() as $feature) {
                $this->assertArrayHasKey(
                    $feature->value,
                    (array) ($definition['limits'] ?? []),
                    "Plan [{$key}] is missing limit [{$feature->value}].",
                );
            }

            foreach (Feature::gates() as $feature) {
                $this->assertArrayHasKey(
                    $feature->value,
                    (array) ($definition['features'] ?? []),
                    "Plan [{$key}] is missing gate [{$feature->value}].",
                );
                $this->assertIsBool(
                    $definition['features'][$feature->value],
                    "Plan [{$key}] gate [{$feature->value}] must be a boolean.",
                );
            }
        }
    }

    public function test_agency_plans_are_unlimited_and_fully_featured(): void
    {
        $catalog = new PlanCatalog;

        $agencyPlans = array_filter($catalog->all(), fn ($plan) => $plan->isAgency());

        $this->assertNotEmpty($agencyPlans, 'Expected at least one agency plan.');

        foreach ($agencyPlans as $plan) {
            foreach (Feature::limits() as $feature) {
                $this->assertNull($plan->limit($feature), "Agency plan [{$plan->key}] caps [{$feature->value}].");
            }

            foreach (Feature::gates() as $feature) {
                $this->assertTrue($plan->allows($feature), "Agency plan [{$plan->key}] lacks [{$feature->value}].");
            }
        }
    }

    public function test_default_plan_keys_resolve(): void
    {
        $catalog = new PlanCatalog;

        $this->assertSame('business_free', $catalog->defaultKeyFor(OrganizationType::Business));
        $this->assertSame('agency_standard', $catalog->defaultKeyFor(OrganizationType::Agency));
        $this->assertNotNull($catalog->find($catalog->defaultKeyFor(OrganizationType::Business)));
    }
}
