<?php

namespace Tests\Feature\Plans;

use App\Actions\Plans\EndPlanGrandfatherAction;
use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Services\Plans\ConfigEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step GTM-0.1 — the promise made when paid agency tiers landed: an agency org that already existed
 * keeps unlimited capacity for 12 months, then moves onto `agency_standard`. The migration makes the
 * promise and `plans:end-grandfather` keeps it; neither may touch anyone else.
 */
class AgencyPlanGrandfatherTest extends TestCase
{
    use RefreshDatabase;

    private function entitlements(): ConfigEntitlements
    {
        return app(ConfigEntitlements::class);
    }

    public function test_a_grandfathered_org_keeps_unlimited_capacity_until_its_date(): void
    {
        $agency = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_legacy',
            'plan_grandfathered_until' => now()->addMonths(6),
        ]);

        $this->assertNull($this->entitlements()->limit($agency, Feature::Brands));

        $result = app(EndPlanGrandfatherAction::class)();

        $this->assertSame(0, $result['moved']);
        $this->assertSame('agency_legacy', $agency->refresh()->plan);
    }

    public function test_the_sweep_moves_an_expired_org_onto_the_standard_tier(): void
    {
        $agency = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_legacy',
            'plan_grandfathered_until' => now()->subDay(),
        ]);

        $result = app(EndPlanGrandfatherAction::class)();

        $this->assertSame(1, $result['moved']);
        $this->assertSame([$agency->id], $result['org_ids']);

        $agency->refresh();

        $this->assertSame('agency_standard', $agency->plan);
        $this->assertNull($agency->plan_grandfathered_until, 'The grandfather date is spent, not left to fire twice.');
        $this->assertSame(15, $this->entitlements()->limit($agency, Feature::Brands));

        // Idempotent: a second sweep has nothing left to do.
        $this->assertSame(0, app(EndPlanGrandfatherAction::class)()['moved']);
    }

    public function test_the_sweep_leaves_paying_and_exempt_orgs_alone(): void
    {
        $paying = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_scale',
            'plan_grandfathered_until' => now()->subYear(),
        ]);

        // No date recorded = a permanent exemption, however old the org is.
        $exempt = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_legacy',
            'plan_grandfathered_until' => null,
        ]);

        $solo = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'business_free',
        ]);

        $this->assertSame(0, app(EndPlanGrandfatherAction::class)()['moved']);

        $this->assertSame('agency_scale', $paying->refresh()->plan);
        $this->assertSame('agency_legacy', $exempt->refresh()->plan);
        $this->assertSame('business_free', $solo->refresh()->plan);
    }

    public function test_the_command_runs_the_sweep(): void
    {
        $agency = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_legacy',
            'plan_grandfathered_until' => now()->subDay(),
        ]);

        $this->artisan('plans:end-grandfather')
            ->expectsOutputToContain('Moved 1 org(s)')
            ->assertSuccessful();

        $this->assertSame('agency_standard', $agency->refresh()->plan);
    }
}
