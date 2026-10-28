<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\SyncSubscriptionAction;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Support\Plans\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-2.4 / B-9.3 — a Stripe subscription reconciles onto `organizations.plan`. Idempotent, and a
 * cancelled subscription reverts to the type default.
 */
class SyncSubscriptionActionTest extends TestCase
{
    use RefreshDatabase;

    private const PRO_PRICE = 'price_business_pro_gbp_monthly';

    private const CREATOR_PRICE = 'price_creator_pro_gbp_monthly';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.plans.business_pro.prices.gbp.monthly.stripe_price_id' => self::PRO_PRICE,
            'plans.plans.creator_pro.prices.gbp.monthly.stripe_price_id' => self::CREATOR_PRICE,
        ]);
    }

    private function sync(): SyncSubscriptionAction
    {
        return new SyncSubscriptionAction(new PlanCatalog);
    }

    private function orgWithSubscription(string $price, string $status = 'active'): Organization
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);

        $org->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
        ]);

        return $org->refresh();
    }

    public function test_an_active_subscription_maps_the_stripe_price_to_the_plan(): void
    {
        $org = $this->orgWithSubscription(self::PRO_PRICE);

        ($this->sync())($org);

        $this->assertSame('business_pro', $org->refresh()->plan);
    }

    public function test_changing_the_price_moves_the_plan(): void
    {
        $org = $this->orgWithSubscription(self::PRO_PRICE);
        ($this->sync())($org);
        $this->assertSame('business_pro', $org->refresh()->plan);

        $org->subscription()->update(['stripe_price' => self::CREATOR_PRICE]);
        ($this->sync())($org->refresh());

        $this->assertSame('creator_pro', $org->refresh()->plan);
    }

    public function test_a_cancelled_subscription_reverts_to_the_type_default(): void
    {
        $org = $this->orgWithSubscription(self::PRO_PRICE);
        ($this->sync())($org);
        $this->assertSame('business_pro', $org->refresh()->plan);

        // Ended in the past → no longer valid.
        $org->subscription()->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
        ($this->sync())($org->refresh());

        $this->assertSame('business_free', $org->refresh()->plan);
    }

    public function test_it_is_idempotent(): void
    {
        $org = $this->orgWithSubscription(self::PRO_PRICE);

        ($this->sync())($org);
        ($this->sync())($org->refresh());
        ($this->sync())($org->refresh());

        $this->assertSame('business_pro', $org->refresh()->plan);
    }

    public function test_an_unmapped_price_leaves_the_plan_unchanged(): void
    {
        $org = $this->orgWithSubscription('price_unknown');
        $org->forceFill(['plan' => 'business_pro'])->save();

        ($this->sync())($org->refresh());

        // Don't silently downgrade a paying customer on a price we don't recognise.
        $this->assertSame('business_pro', $org->refresh()->plan);
    }
}
