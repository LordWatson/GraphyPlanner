<?php

namespace Tests\Feature\Billing;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookHandled;
use Tests\TestCase;

/**
 * Step CG-2.4 / B-9.3 — the webhook → plan sync, and that a replayed event is a no-op (idempotency),
 * plus that the real signed endpoint verifies the Stripe signature.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PRO_PRICE = 'price_business_pro_gbp_monthly';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.plans.business_pro.prices.gbp.monthly.stripe_price_id' => self::PRO_PRICE,
            'cashier.webhook.secret' => 'whsec_test',
        ]);
    }

    private function proOrg(string $customer): Organization
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'business_free',
            'stripe_id' => $customer,
        ]);

        $org->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => self::PRO_PRICE,
            'quantity' => 1,
        ]);

        return $org->refresh();
    }

    public function test_a_subscription_event_syncs_the_plan_and_records_the_event(): void
    {
        $org = $this->proOrg('cus_sync');

        event(new WebhookHandled([
            'id' => 'evt_sync_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_sync']],
        ]));

        $this->assertSame('business_pro', $org->refresh()->plan);
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_sync_1']);
    }

    public function test_a_replayed_event_is_idempotent(): void
    {
        $org = $this->proOrg('cus_replay');

        $payload = [
            'id' => 'evt_replay_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_replay']],
        ];

        event(new WebhookHandled($payload));
        event(new WebhookHandled($payload));
        event(new WebhookHandled($payload));

        $this->assertSame('business_pro', $org->refresh()->plan);
        $this->assertSame(1, StripeWebhookEvent::query()->where('stripe_event_id', 'evt_replay_1')->count());
    }

    public function test_non_subscription_events_are_ignored(): void
    {
        $this->proOrg('cus_ignore');

        event(new WebhookHandled([
            'id' => 'evt_ping',
            'type' => 'ping',
            'data' => ['object' => ['customer' => 'cus_ignore']],
        ]));

        $this->assertDatabaseMissing('stripe_webhook_events', ['stripe_event_id' => 'evt_ping']);
    }

    public function test_the_signed_endpoint_accepts_a_correctly_signed_request(): void
    {
        // A benign event type (no Cashier object parsing, no Stripe API call) is enough to prove the
        // signature is verified and the endpoint is reachable; the sync path itself is covered by the
        // listener tests above.
        $payload = json_encode([
            'id' => 'evt_signed_1',
            'type' => 'balance.available',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

        $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            $payload,
        )->assertOk();
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        // Cashier's signature middleware refuses an unsigned/invalid request (403) before our code runs.
        $this->postJson('/stripe/webhook', [
            'id' => 'evt_unsigned',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_x']],
        ])->assertForbidden();

        $this->assertDatabaseMissing('stripe_webhook_events', ['stripe_event_id' => 'evt_unsigned']);
    }
}
