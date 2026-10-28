<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Step CG-2.7 / B-12.2 — the public pricing/segment pages, with the table generated from
 * config/plans.php (never drifting from enforcement). Guest-accessible.
 */
class PricingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pricing_page_lists_every_public_plan_from_config(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('marketing/pricing')
                ->has('pricing.plans', 3) // business_free, business_pro, creator_pro — agency is not public
                ->where('pricing.plans.0.key', 'business_free')
                ->has('pricing.currencies.gbp')
                ->has('pricing.currencies.usd'));
    }

    public function test_the_segment_pages_render_for_guests(): void
    {
        $this->get(route('for-business'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/for-business')->has('pricing.plans'));

        $this->get(route('for-creators'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/for-creators')->has('pricing.plans'));
    }

    public function test_the_pricing_payload_is_amounts_only_never_stripe_ids(): void
    {
        // Pro is £15/mo; the payload carries amounts + currency only, never a Stripe price id.
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.plans.1.key', 'business_pro')
                ->where('pricing.plans.1.prices.gbp.monthly', 15)
                ->where('pricing.plans.1.prices.usd.monthly', 19)
                ->missing('pricing.plans.1.prices.gbp.stripe_price_id'));
    }
}
