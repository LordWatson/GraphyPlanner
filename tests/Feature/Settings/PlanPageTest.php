<?php

namespace Tests\Feature\Settings;

use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Step CG-2.5 / B-9.3 — the Owner-only plan page and the guarded checkout (never a live Stripe call
 * while billing is off or a price is missing).
 */
class PlanPageTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $plan = 'business_free'): Organization
    {
        return Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => $plan]);
    }

    public function test_an_owner_sees_the_plan_page(): void
    {
        $org = $this->business();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('plan.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/plan')
                ->where('current.key', 'business_free')
                ->has('usage', 3)
                ->has('plans'));
    }

    public function test_a_non_owner_cannot_see_billing(): void
    {
        $org = $this->business();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($strategist)
            ->get(route('plan.edit'))
            ->assertForbidden();
    }

    public function test_a_client_reviewer_cannot_see_billing(): void
    {
        $org = $this->business();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create();

        $this->actingAs($reviewer)
            ->get(route('plan.edit'))
            ->assertForbidden();
    }

    public function test_checkout_is_blocked_when_billing_is_off(): void
    {
        $org = $this->business();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'business_pro', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertRedirect();

        // No subscription was created (no Stripe call made).
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_checkout_is_blocked_when_the_plan_has_no_stripe_price(): void
    {
        // Billing is on for this org (per-org override), but no price id is configured yet.
        $org = $this->business();
        $org->forceFill(['billing_enabled' => true])->save();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'business_pro', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseCount('subscriptions', 0);
    }
}
