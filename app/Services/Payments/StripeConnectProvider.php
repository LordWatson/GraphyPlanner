<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Exceptions\Store\PaymentProviderException;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Step CG-4.1 — Stripe Connect (Express) for creators' own sales, driven with stripe-php directly.
 *
 * This is **not** Cashier: Cashier is how a creator pays Alima, this is how a creator's buyer pays
 * the creator. They use different keys (`payments.stripe.*` vs `cashier.*`), different webhook
 * endpoints and secrets, and different tables (Principle 4).
 *
 * Every charge is a **direct charge on the connected account** (`Stripe-Account` header) with no
 * `application_fee_amount`, which is what "0% platform fee" means in practice: the money never
 * touches Alima's balance. A paid question is created with `capture_method: manual`, so the card is
 * authorised at checkout and only captured when the creator answers ({@see self::capture()}).
 */
class StripeConnectProvider implements PaymentProvider
{
    private ?StripeClient $client = null;

    public function createConnectedAccount(Organization $organization): string
    {
        $country = strtoupper((string) config('payments.stripe.country', 'GB'));

        return $this->call('createConnectedAccount', fn (): string => (string) $this->client()->accounts->create([
            'type' => 'express',
            'country' => $country,
            // No email: Express onboarding collects (and verifies) the creator's own, and we'd only
            // be guessing it from a staff user.
            'business_type' => 'individual',
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'business_profile' => [
                'name' => $organization->name,
            ],
            'metadata' => ['org_id' => (string) $organization->id],
        ])->id);
    }

    public function onboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $this->call('onboardingLink', fn (): string => (string) $this->client()->accountLinks->create([
            'account' => $accountId,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ])->url);
    }

    public function accountStatus(string $accountId): array
    {
        /**
         * @return array{charges_enabled: bool, payouts_enabled: bool, requirements_due: list<string>, country: string|null, default_currency: string|null}
         */
        $fetch = function () use ($accountId): array {
            $account = $this->client()->accounts->retrieve($accountId, []);

            return [
                'charges_enabled' => (bool) $account->charges_enabled,
                'payouts_enabled' => (bool) $account->payouts_enabled,
                'requirements_due' => array_values(array_map('strval', (array) ($account->requirements?->currently_due ?? []))),
                'country' => $account->country !== null ? strtoupper((string) $account->country) : null,
                'default_currency' => $account->default_currency !== null ? strtoupper((string) $account->default_currency) : null,
            ];
        };

        return $this->call('accountStatus', $fetch);
    }

    public function createCheckout(PaymentAccount $account, Order $order, string $successUrl, string $cancelUrl): array
    {
        $product = $order->product;

        $payload = [
            'mode' => 'payment',
            'customer_email' => $order->buyer_email,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => (string) $order->id],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => $order->amount_minor,
                    'product_data' => ['name' => $product->title],
                ],
            ]],
            'payment_intent_data' => [
                'metadata' => ['order_id' => (string) $order->id],
                // Principle 3: no application fee, ever. A direct charge with none means the
                // creator keeps 100% of the sale.
                ...($product->type->isAuthorisedThenCaptured() ? ['capture_method' => 'manual'] : []),
            ],
        ];

        return $this->call('createCheckout', function () use ($payload, $account): array {
            $session = $this->client()->checkout->sessions->create($payload, $this->onAccount($account));

            return ['id' => (string) $session->id, 'url' => (string) $session->url];
        });
    }

    public function capture(PaymentAccount $account, Order $order): void
    {
        $intent = $this->requirePaymentId($order, 'capture');

        $this->call('capture', fn () => $this->client()->paymentIntents->capture($intent, [], $this->onAccount($account)));
    }

    public function cancelAuthorisation(PaymentAccount $account, Order $order): void
    {
        $intent = $this->requirePaymentId($order, 'cancelAuthorisation');

        $this->call('cancelAuthorisation', fn () => $this->client()->paymentIntents->cancel($intent, [], $this->onAccount($account)));
    }

    public function refund(PaymentAccount $account, Order $order): void
    {
        $intent = $this->requirePaymentId($order, 'refund');

        $this->call('refund', fn () => $this->client()->refunds->create(
            ['payment_intent' => $intent],
            $this->onAccount($account),
        ));
    }

    public function verifyWebhook(string $payload, ?string $signature): ?array
    {
        $secret = (string) config('payments.stripe.webhook_secret');

        if ($secret === '' || $signature === null || $signature === '') {
            Log::warning('Connect webhook: missing signature or signing secret');

            return null;
        }

        try {
            // Stripe's own tolerance check rejects a stale delivery (replay protection), exactly as
            // the Upload-Post webhook does with its timestamp skew window.
            return Webhook::constructEvent($payload, $signature, $secret)->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::warning('Connect webhook: signature rejected', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The `Stripe-Account` header that makes a call act *as* the creator.
     *
     * @return array{stripe_account: string}
     */
    private function onAccount(PaymentAccount $account): array
    {
        return ['stripe_account' => $account->external_account_id];
    }

    private function requirePaymentId(Order $order, string $operation): string
    {
        $intent = $order->external_payment_id;

        if ($intent === null || $intent === '') {
            throw new PaymentProviderException('Order has no payment to act on.', $operation);
        }

        return $intent;
    }

    /**
     * Run a provider call, logging the boundary with ids only (never a key, a card or a payload) and
     * normalising any vendor failure into {@see PaymentProviderException}.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function call(string $operation, callable $callback): mixed
    {
        try {
            $result = $callback();

            Log::info('Stripe Connect call ok', ['operation' => $operation]);

            return $result;
        } catch (Throwable $e) {
            Log::error('Stripe Connect call failed', ['operation' => $operation, 'exception' => $e::class]);

            throw PaymentProviderException::from($operation, $e);
        }
    }

    private function client(): StripeClient
    {
        $secret = (string) config('payments.stripe.secret');

        if ($secret === '') {
            throw new PaymentProviderException('No Stripe Connect secret is configured.', 'client');
        }

        return $this->client ??= new StripeClient($secret);
    }
}
