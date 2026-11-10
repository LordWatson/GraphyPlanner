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

    /**
     * `phpunit.xml` blanks `STRIPE_SECRET`, which `BillingGate` reads as "billing unavailable". A
     * test about anything downstream of the gate has to get past it first.
     */
    private function fakeStripeSecret(): void
    {
        config()->set('cashier.secret', 'sk_test_not_a_real_key');
    }

    private function agency(string $plan = 'agency_starter'): Organization
    {
        return Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => $plan]);
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

    /** Step GTM-5.1: an agency owner is shown the agency tiers, never the creator/business pricing. */
    public function test_an_agency_owner_only_sees_agency_plans(): void
    {
        $org = $this->agency();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('plan.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plans', 3)
                ->where('plans.0.key', 'agency_starter')
                ->where('plans.1.key', 'agency_standard')
                ->where('plans.2.key', 'agency_scale')
                ->has('plans.0.has_price', fn (AssertableInertia $price) => $price
                    ->has('monthly')
                    ->has('annual')));
    }

    public function test_a_business_owner_is_never_offered_an_agency_plan(): void
    {
        $org = $this->business();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('plan.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plans', 3)
                ->where('plans.0.key', 'business_free')
                ->where('plans.1.key', 'business_pro')
                ->where('plans.2.key', 'creator_pro'));
    }

    public function test_an_agency_cannot_check_out_a_business_plan(): void
    {
        $org = $this->agency();
        $org->forceFill(['billing_enabled' => true])->save();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'creator_pro', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_business_cannot_check_out_an_agency_plan(): void
    {
        $org = $this->business();
        $org->forceFill(['billing_enabled' => true])->save();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'agency_standard', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * Regression: a price id configured while `STRIPE_SECRET` is blank used to reach Cashier and
     * throw `api_key cannot be the empty string` from inside the Stripe SDK — a 500 on the Upgrade
     * button. `BillingGate::configured()` now turns billing off instead.
     */
    public function test_checkout_does_not_reach_stripe_without_an_api_key(): void
    {
        config()->set('plans.plans.business_pro.prices.gbp.monthly.stripe_price_id', 'price_real_looking');
        config()->set('cashier.secret', '');

        $org = $this->business();
        $org->forceFill(['billing_enabled' => true])->save();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'business_pro', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * Regression: `checkout()` used to call `newSubscription()` unconditionally, so an org that
     * already subscribed got a *second* Stripe subscription (and a second bill) instead of a swap.
     * A press on the tier it's already on must not reach Stripe at all.
     */
    public function test_checkout_on_the_current_plan_does_not_reach_stripe(): void
    {
        config()->set('plans.plans.agency_standard.prices.gbp.monthly.stripe_price_id', 'price_agency_standard');
        $this->fakeStripeSecret();

        $org = $this->agency('agency_standard');
        $org->forceFill(['billing_enabled' => true])->save();
        $org->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_agency_standard',
            'quantity' => 1,
        ]);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'agency_standard', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertRedirect();

        // Still the one subscription — no second one opened, and no Stripe call to open it.
        $this->assertDatabaseCount('subscriptions', 1);
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
        // Billing is on for this org (per-org override), but no price id is configured yet. Set it
        // explicitly rather than rely on `.env` being blank — a developer with a real price id in
        // theirs would otherwise send this test to Stripe.
        config()->set('plans.plans.business_pro.prices.gbp.monthly.stripe_price_id', null);
        $this->fakeStripeSecret();

        $org = $this->business();
        $org->forceFill(['billing_enabled' => true])->save();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->post(route('plan.checkout'), ['plan' => 'business_pro', 'currency' => 'gbp', 'interval' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseCount('subscriptions', 0);
    }
}
