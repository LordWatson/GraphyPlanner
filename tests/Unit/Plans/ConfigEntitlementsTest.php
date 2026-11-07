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

            // Under the cap: allowed (no exception). Step GTM-4.1: a cap of **0** is a legitimate
            // plan value ("none of this at all") and has no room below it to check.
            if ($cap > 0) {
                $entitlements->ensure($org, $feature, $cap - 1);
                $this->addToAssertionCount(1);
            }

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
        $agency = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_starter']);
        $entitlements = $this->entitlements();

        // Step GTM-1.3: the comment inbox is the one gate Free deliberately keeps (plans.md). Every
        // other gate is a paid gate, and this test is what stops one being added to Free by accident.
        $freeKeeps = [Feature::Inbox];

        // Step GTM-3.1 / GTM-5.2: white labelling and paid-ads reporting are agency features —
        // one is an agency putting its name on surfaces its own clients see, the other is
        // reporting a client's media spend — so creator_pro doesn't grant either. Every agency
        // tier grants both. Every other gate is on creator_pro.
        $agencyOnly = [Feature::WhiteLabel, Feature::AdsReporting];

        foreach (Feature::gates() as $feature) {
            $granting = in_array($feature, $agencyOnly, true) ? $agency : $creator;
            $plan = in_array($feature, $agencyOnly, true) ? 'an agency plan' : 'creator_pro';

            $this->assertTrue($entitlements->allows($granting, $feature), "{$plan} should allow [{$feature->value}].");
            $entitlements->ensure($granting, $feature);

            if (in_array($feature, $agencyOnly, true)) {
                $this->assertFalse(
                    $entitlements->allows($creator, $feature),
                    "creator_pro should not allow the agency-only gate [{$feature->value}].",
                );
            }

            if (in_array($feature, $freeKeeps, true)) {
                $this->assertTrue($entitlements->allows($free, $feature), "free should allow [{$feature->value}].");
                $entitlements->ensure($free, $feature);

                continue;
            }

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

    public function test_grandfathered_agency_orgs_are_never_limited(): void
    {
        $agency = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_legacy']);
        $entitlements = $this->entitlements();

        foreach (Feature::limits() as $feature) {
            $this->assertNull($entitlements->limit($agency, $feature));
            $entitlements->ensure($agency, $feature, 10_000); // never throws
        }

        foreach (Feature::gates() as $feature) {
            $this->assertTrue($entitlements->allows($agency, $feature));
            $entitlements->ensure($agency, $feature);
        }

        $this->assertSame('agency_legacy', $agency->effectivePlanKey());
    }

    public function test_an_agency_org_without_a_plan_gets_the_starter_caps(): void
    {
        // Step GTM-0.1: agencies are no longer unlimited by default — an org that has never paid
        // sits on the cheapest tier's capacity, and every gate still works.
        $agency = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => null]);
        $entitlements = $this->entitlements();

        $this->assertSame('agency_starter', $agency->effectivePlanKey());
        $this->assertSame(5, $entitlements->limit($agency, Feature::Brands));
        $this->assertSame(3, $entitlements->limit($agency, Feature::TeamSeats));

        foreach (Feature::gates() as $feature) {
            $this->assertTrue($entitlements->allows($agency, $feature));
        }

        $entitlements->ensure($agency, Feature::Brands, 4); // headroom: never throws

        $this->expectException(PlanLimitReachedException::class);
        $entitlements->ensure($agency, Feature::Brands, 5);
    }

    public function test_an_agency_at_its_cap_is_offered_the_next_agency_tier(): void
    {
        $agency = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_starter']);

        try {
            $this->entitlements()->ensure($agency, Feature::Brands, 5);
            $this->fail('Expected rejection.');
        } catch (PlanLimitReachedException $e) {
            // Never a solo plan: that's a different product, not an upgrade.
            $this->assertNotNull($e->upgradeTo);
            $this->assertSame('agency_standard', $e->upgradeTo->key);
        }
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
