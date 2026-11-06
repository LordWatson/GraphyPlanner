<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step CG-4.1 — the store with no payment provider configured (local dev, CI, and any environment
 * without Connect keys). It fakes the *shape* of the flow so the UI and the order state machine can
 * be exercised end to end, and fakes nothing else:
 *
 *  - `createCheckout` returns a local URL, so nobody is ever sent to a real card form;
 *  - `verifyWebhook` **always refuses**. Without a signing secret there is nothing to verify, and a
 *    provider that waved deliveries through would be a hole in a money path, not a convenience.
 */
class NullPaymentProvider implements PaymentProvider
{
    public function createConnectedAccount(Organization $organization): string
    {
        return 'acct_null_'.$organization->id;
    }

    public function onboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $returnUrl;
    }

    public function accountStatus(string $accountId): array
    {
        return [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'requirements_due' => [],
            'country' => strtoupper((string) config('payments.stripe.country', 'GB')),
            'default_currency' => 'GBP',
        ];
    }

    public function createCheckout(PaymentAccount $account, Order $order, string $successUrl, string $cancelUrl): array
    {
        $id = 'cs_null_'.Str::lower(Str::random(24));

        Log::info('Null payment provider: pretend checkout', ['order_id' => $order->id]);

        return ['id' => $id, 'url' => $cancelUrl];
    }

    public function capture(PaymentAccount $account, Order $order): void {}

    public function cancelAuthorisation(PaymentAccount $account, Order $order): void {}

    public function refund(PaymentAccount $account, Order $order): void {}

    public function verifyWebhook(string $payload, ?string $signature): ?array
    {
        Log::warning('Connect webhook refused: no payment provider is configured.');

        return null;
    }
}
