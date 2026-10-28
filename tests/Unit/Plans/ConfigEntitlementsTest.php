<?php

namespace Tests\Unit\Plans;

use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Organization;
use App\Services\Plans\ConfigEntitlements;
use App\Support\Plans\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-2.2 / B-9.1 — the plan gate: one case per feature (limit + gate), and agency orgs are
 * never capped.
 */
class ConfigEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function entitlements(): ConfigEntitlements
    {
        return new ConfigEntitlements(new PlanCatalog);
    }

    public function test_every_limit_feature_is_enforced_at_its_cap_on_the_free_plan(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
        $entitlements = $this->entitlements();

        foreach (Feature::limits() as $feature) {
            $cap = $entitlements->limit($org, $feature);

            if ($cap === null) {
                // Unlimited on free — nothing to enforce.
                $entitlements->ensure($org, $feature, 999_999);

                continue;
            }

            // Under the cap: allowed (no exception).
            $entitlements->ensure($org, $feature, max(0, $cap - 1));
            $this->addToAssertionCount(1);

            // At the cap: rejected.
            try {
                $entitlements->ensure($org, $feature, $cap);
                $this->fail("Expected a PlanLimitReachedException for [{$feature->value}] at its cap.");
            } catch (PlanLimitReachedException $e) {
                $this->assertSame($feature, $e->feature);
                $this->assertSame($cap, $e->limit);
            }
        }
    }

    public function test_every_gate_feature_is_denied_on_free_and_allowed_on_a_plan_that_grants_it(): void
    {
        $free = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
        $creator = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $entitlements = $this->entitlements();

        foreach (Feature::gates() as $feature) {
            // creator_pro grants every gate (see config/plans.php), so it always passes.
            $this->assertTrue($entitlements->allows($creator, $feature), "creator_pro should allow [{$feature->value}].");
            $entitlements->ensure($creator, $feature);

            // Free grants none of them.
            $this->assertFalse($entitlements->allows($free, $feature), "free should deny [{$feature->value}].");
            $this->expectExceptionObjectForGate($entitlements, $free, $feature);
        }
    }

    private function expectExceptionObjectForGate(ConfigEntitlements $entitlements, Organization $org, Feature $feature): void
    {
        try {
            $entitlements->ensure($org, $feature);
            $this->fail("Expected a PlanLimitReachedException for gate [{$feature->value}].");
        } catch (PlanLimitReachedException $e) {
            $this->assertSame($feature, $e->feature);
        }
    }

    public function test_agency_orgs_are_never_limited(): void
    {
        $agency = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => null]);
        $entitlements = $this->entitlements();

        foreach (Feature::limits() as $feature) {
            $this->assertNull($entitlements->limit($agency, $feature));
            $entitlements->ensure($agency, $feature, 10_000); // never throws
        }

        foreach (Feature::gates() as $feature) {
            $this->assertTrue($entitlements->allows($agency, $feature));
            $entitlements->ensure($agency, $feature);
        }

        $this->assertSame('agency_standard', $agency->effectivePlanKey());
    }

    public function test_upgrade_suggestion_points_at_a_plan_that_unblocks_the_feature(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);

        try {
            $this->entitlements()->ensure($org, Feature::GridPlanner);
            $this->fail('Expected rejection.');
        } catch (PlanLimitReachedException $e) {
            $this->assertNotNull($e->upgradeTo);
            $this->assertTrue($e->upgradeTo->allows(Feature::GridPlanner));
        }
    }
}
