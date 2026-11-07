<?php

namespace Tests\Unit\Plans;

use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Support\Plans\PlanCatalog;
use Tests\TestCase;

/**
 * Step CG-2.1 — every plan in config/plans.php must define every Feature (no silent omissions).
 * Step GTM-0.1 — agency plans all grant every gate and differ only on capacity; only the
 * grandfathered `agency_legacy` plan is still unlimited.
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

    public function test_every_agency_plan_grants_every_gate(): void
    {
        $catalog = new PlanCatalog;

        $agencyPlans = array_filter($catalog->all(), fn ($plan) => $plan->isAgency());

        $this->assertNotEmpty($agencyPlans, 'Expected at least one agency plan.');

        // Step GTM-0.1: agency tiers differ on capacity, never on capability. A feature that one
        // agency tier can use, all of them can.
        foreach ($agencyPlans as $plan) {
            foreach (Feature::gates() as $feature) {
                $this->assertTrue($plan->allows($feature), "Agency plan [{$plan->key}] lacks [{$feature->value}].");
            }
        }
    }

    public function test_the_grandfathered_agency_plan_stays_unlimited(): void
    {
        $legacy = (new PlanCatalog)->get('agency_legacy');

        foreach (Feature::limits() as $feature) {
            $this->assertNull($legacy->limit($feature), "agency_legacy caps [{$feature->value}].");
        }

        $this->assertFalse($legacy->isPublic(), 'agency_legacy must never be sold.');
    }

    public function test_paid_agency_tiers_cap_capacity_and_rise_with_price(): void
    {
        $catalog = new PlanCatalog;

        $starter = $catalog->get('agency_starter');
        $standard = $catalog->get('agency_standard');
        $scale = $catalog->get('agency_scale');

        $this->assertSame([5, 3], [$starter->limit(Feature::Brands), $starter->limit(Feature::TeamSeats)]);
        $this->assertSame([15, 10], [$standard->limit(Feature::Brands), $standard->limit(Feature::TeamSeats)]);
        $this->assertSame(50, $scale->limit(Feature::Brands));
        $this->assertNull($scale->limit(Feature::TeamSeats), 'Scale sells unlimited seats.');

        // Price and capacity move in the same direction, or the ladder makes no sense.
        $this->assertSame([49, 99, 199], [
            $starter->price('gbp', 'monthly'),
            $standard->price('gbp', 'monthly'),
            $scale->price('gbp', 'monthly'),
        ]);
        $this->assertTrue($starter->limit(Feature::Brands) < $standard->limit(Feature::Brands));
        $this->assertTrue($standard->limit(Feature::Brands) < $scale->limit(Feature::Brands));

        foreach ([$starter, $standard, $scale] as $plan) {
            $this->assertTrue($plan->isPublic(), "Agency tier [{$plan->key}] must be on the pricing table.");
            $this->assertTrue($plan->isPaid(), "Agency tier [{$plan->key}] must carry a price.");
        }
    }

    public function test_default_plan_keys_resolve(): void
    {
        $catalog = new PlanCatalog;

        $this->assertSame('business_free', $catalog->defaultKeyFor(OrganizationType::Business));
        // Step GTM-0.1: an unpaid agency org sits on the cheapest paid tier's caps, not unlimited.
        $this->assertSame('agency_starter', $catalog->defaultKeyFor(OrganizationType::Agency));
        $this->assertNotNull($catalog->find($catalog->defaultKeyFor(OrganizationType::Business)));
    }
}
