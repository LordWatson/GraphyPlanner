<?php

namespace App\Actions\Store;

use App\Contracts\PaymentProvider;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.1 — reconcile a connected account's capabilities. Two callers:
 *
 *  - the `account.updated` webhook, which already carries the fresh account object (passed in as
 *    `$status`, so no extra API call);
 *  - the creator coming back from onboarding, where we ask the provider directly.
 *
 * Only flags and requirement *names* are stored. Nothing from Stripe's payload is persisted beyond
 * the shape {@see PaymentProvider::accountStatus()} defines.
 *
 * @phpstan-type AccountStatus array{charges_enabled: bool, payouts_enabled: bool, requirements_due: list<string>, country: string|null, default_currency: string|null}
 */
class SyncPaymentAccountAction
{
    public function __construct(private readonly PaymentProvider $provider) {}

    /**
     * @param  array{charges_enabled: bool, payouts_enabled: bool, requirements_due: list<string>, country: string|null, default_currency: string|null}|null  $status
     */
    public function __invoke(PaymentAccount $account, ?array $status = null): PaymentAccount
    {
        $status ??= $this->provider->accountStatus($account->external_account_id);

        $account->update([
            'charges_enabled' => $status['charges_enabled'],
            'payouts_enabled' => $status['payouts_enabled'],
            'requirements_due' => $status['requirements_due'] === [] ? null : $status['requirements_due'],
            'country' => $status['country'] ?? $account->country,
            'default_currency' => $status['default_currency'] ?? $account->default_currency,
        ]);

        Log::info('Connect account synced', [
            'org_id' => $account->org_id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'requirements_due' => count($account->requirements_due ?? []),
        ]);

        return $account;
    }

    /**
     * Read the flags straight off a webhook's `account` object, so handling `account.updated` needs
     * no round trip. Unknown/missing fields fall back to "not enabled", never to "enabled".
     *
     * @param  array<string, mixed>  $object
     * @return array{charges_enabled: bool, payouts_enabled: bool, requirements_due: list<string>, country: string|null, default_currency: string|null}
     */
    public static function statusFromWebhookObject(array $object): array
    {
        /** @var array<int, mixed> $due */
        $due = $object['requirements']['currently_due'] ?? [];
        $country = $object['country'] ?? null;
        $currency = $object['default_currency'] ?? null;

        return [
            'charges_enabled' => (bool) ($object['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($object['payouts_enabled'] ?? false),
            'requirements_due' => array_values(array_map('strval', $due)),
            'country' => is_string($country) ? strtoupper($country) : null,
            'default_currency' => is_string($currency) ? strtoupper($currency) : null,
        ];
    }
}
