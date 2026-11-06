<?php

namespace App\Contracts;

use App\Exceptions\Store\PaymentProviderException;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Services\Payments\NullPaymentProvider;
use App\Services\Payments\StripeConnectProvider;

/**
 * Step CG-4.1 — taking money on a creator's behalf. One implementation today
 * ({@see StripeConnectProvider}, Stripe Connect Express — **not** Cashier, which is how creators pay
 * *us*), plus {@see NullPaymentProvider} for environments and tests with no Stripe keys.
 *
 * Two rules shape this interface:
 *
 *  - **The creator is the merchant.** Every call names the creator's connected account; the charge
 *    is a direct charge on it with no application fee (Principle 3 — 0% platform fee).
 *  - **Alima never holds card data or a creator's secret.** The only identifiers that cross this
 *    boundary are Stripe's own ids, and nothing here is ever logged.
 *
 * Implementations throw {@see PaymentProviderException} on a provider failure so Actions can
 * surface one friendly message instead of leaking vendor errors.
 */
interface PaymentProvider
{
    /**
     * Create the creator's connected account and return the provider's id for it.
     */
    public function createConnectedAccount(Organization $organization): string;

    /**
     * A single-use hosted onboarding (or "finish your details") link for the account.
     */
    public function onboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string;

    /**
     * The account's current capabilities, as the provider sees them.
     *
     * @return array{charges_enabled: bool, payouts_enabled: bool, requirements_due: list<string>, country: string|null, default_currency: string|null}
     */
    public function accountStatus(string $accountId): array;

    /**
     * Start a hosted checkout for a pending order on the creator's account. A paid question is
     * authorised only (manual capture); everything else is charged immediately.
     *
     * @return array{id: string, url: string}
     */
    public function createCheckout(PaymentAccount $account, Order $order, string $successUrl, string $cancelUrl): array;

    /**
     * Capture a previously authorised payment (a paid question, once answered).
     */
    public function capture(PaymentAccount $account, Order $order): void;

    /**
     * Release an authorisation we never captured (an unanswered paid question).
     */
    public function cancelAuthorisation(PaymentAccount $account, Order $order): void;

    /**
     * Refund a captured payment in full.
     */
    public function refund(PaymentAccount $account, Order $order): void;

    /**
     * Verify a webhook delivery's signature and return its decoded event, or null when the
     * signature is missing, wrong, or too old to trust.
     *
     * @return array<string, mixed>|null
     */
    public function verifyWebhook(string $payload, ?string $signature): ?array;
}
