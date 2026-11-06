<?php

namespace Tests\Feature\Store;

use App\Contracts\PaymentProvider;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Client;
use App\Models\ConnectWebhookEvent;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Services\Payments\StripeConnectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step CG-4.1 / CG-4.2 — the Connect webhook endpoint, driven with properly signed fixtures (the
 * real {@see StripeConnectProvider} verification, not a stub, because the signature *is* the
 * authorisation on this route).
 *
 * The secret here is a test one and only ever used to sign the fixtures below.
 */
class ConnectWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private Organization $org;

    private Client $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'payments.provider' => 'stripe',
            'payments.stripe.secret' => 'sk_test_not_used',
            'payments.stripe.webhook_secret' => self::SECRET,
        ]);
        $this->app->bind(PaymentProvider::class, StripeConnectProvider::class);

        $this->org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $this->brand = Client::factory()->for($this->org, 'organization')->primary()->create();
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: array<string, string>}
     */
    private function delivery(array $event): array
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return [$payload, ['Stripe-Signature' => "t={$timestamp},v1={$signature}", 'Content-Type' => 'application/json']];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function deliver(array $event): TestResponse
    {
        [$payload, $headers] = $this->delivery($event);

        return $this->call('POST', route('webhooks.stripe-connect'), [], [], [], $this->transformHeadersToServerVars($headers), $payload);
    }

    private function account(): PaymentAccount
    {
        return PaymentAccount::create([
            'org_id' => $this->org->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_test',
            'charges_enabled' => false,
            'payouts_enabled' => false,
        ]);
    }

    private function pendingOrder(): Order
    {
        $product = Product::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'type' => ProductType::Tip,
            'title' => 'Tip jar',
            'price_minor' => 500,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
        ]);

        return Order::create([
            'org_id' => $this->org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 500,
            'currency' => 'GBP',
            'status' => OrderStatus::Pending,
            'external_session_id' => 'cs_test_1',
        ]);
    }

    public function test_an_unsigned_or_wrongly_signed_delivery_is_refused(): void
    {
        $this->account();

        $this->postJson(route('webhooks.stripe-connect'), ['id' => 'evt_1', 'type' => 'account.updated'])
            ->assertStatus(400);

        $payload = (string) json_encode(['id' => 'evt_1', 'type' => 'account.updated']);
        $this->call(
            'POST',
            route('webhooks.stripe-connect'),
            [], [], [],
            $this->transformHeadersToServerVars(['Stripe-Signature' => 't='.time().',v1=deadbeef']),
            $payload,
        )->assertStatus(400);

        $this->assertSame(0, ConnectWebhookEvent::query()->count());
    }

    public function test_account_updated_syncs_the_capabilities_from_the_event(): void
    {
        $account = $this->account();

        $this->deliver([
            'id' => 'evt_account_1',
            'type' => 'account.updated',
            'data' => ['object' => [
                'id' => 'acct_test',
                'charges_enabled' => true,
                'payouts_enabled' => false,
                'country' => 'gb',
                'default_currency' => 'gbp',
                'requirements' => ['currently_due' => ['individual.verification.document']],
            ]],
        ])->assertOk();

        $account->refresh();
        $this->assertTrue($account->charges_enabled);
        $this->assertFalse($account->payouts_enabled);
        $this->assertSame('GB', $account->country);
        $this->assertSame(['individual.verification.document'], $account->requirements_due);
    }

    public function test_a_replayed_event_is_processed_once(): void
    {
        $this->account();
        $order = $this->pendingOrder();

        $event = [
            'id' => 'evt_checkout_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'amount_total' => 500,
                'currency' => 'gbp',
                'payment_intent' => 'pi_test_1',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertSame(1, $order->events()->where('type', 'status.paid')->count());
        $this->assertSame(1, ConnectWebhookEvent::query()->where('stripe_event_id', 'evt_checkout_1')->count());
    }

    public function test_a_refund_taken_at_stripe_is_mirrored_here(): void
    {
        $this->account();
        $order = $this->pendingOrder();
        $order->update(['status' => OrderStatus::Fulfilled, 'paid_at' => now(), 'fulfilled_at' => now(), 'external_payment_id' => 'pi_test_2']);

        $this->deliver([
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'data' => ['object' => ['payment_intent' => 'pi_test_2']],
        ])->assertOk();

        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
    }

    public function test_an_unknown_event_type_is_acknowledged_and_ignored(): void
    {
        $this->deliver(['id' => 'evt_other_1', 'type' => 'payout.paid', 'data' => ['object' => []]])
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_a_cashier_signed_delivery_is_rejected_by_the_connect_endpoint(): void
    {
        // Principle 4: the two integrations share no secret, so a subscription webhook signed with
        // Cashier's secret must not be accepted here.
        $payload = (string) json_encode(['id' => 'evt_cashier', 'type' => 'customer.subscription.updated']);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_cashier_secret');

        $this->call(
            'POST',
            route('webhooks.stripe-connect'),
            [], [], [],
            $this->transformHeadersToServerVars(['Stripe-Signature' => "t={$timestamp},v1={$signature}"]),
            $payload,
        )->assertStatus(400);
    }
}
