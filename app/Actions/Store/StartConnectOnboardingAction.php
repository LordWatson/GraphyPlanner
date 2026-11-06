<?php

namespace App\Actions\Store;

use App\Contracts\Entitlements;
use App\Contracts\PaymentProvider;
use App\Enums\Feature;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.1 — put the creator in front of Stripe's hosted onboarding, creating their connected
 * account on the first run and reusing it afterwards (so "finish your details" and "I lost the tab"
 * both work). Returns the one-time link for the controller to redirect to.
 *
 * The store is a plan gate ({@see Feature::Store}): there's no point onboarding an org that can't
 * sell, so the gate is checked here rather than at the first sale.
 */
class StartConnectOnboardingAction
{
    public function __construct(private readonly PaymentProvider $provider) {}

    public function __invoke(Organization $organization, string $returnUrl, string $refreshUrl): string
    {
        app(Entitlements::class)->ensure($organization, Feature::Store);

        $account = DB::transaction(function () use ($organization): PaymentAccount {
            $account = PaymentAccount::query()->where('org_id', $organization->id)->lockForUpdate()->first();

            if ($account !== null) {
                return $account;
            }

            // Created inside the transaction so two tabs can't open two Stripe accounts for one org.
            $externalId = $this->provider->createConnectedAccount($organization);

            Log::info('Connect account created', ['org_id' => $organization->id]);

            return PaymentAccount::create([
                'org_id' => $organization->id,
                'provider' => (string) config('payments.provider', 'stripe'),
                'external_account_id' => $externalId,
            ]);
        });

        return $this->provider->onboardingLink($account->external_account_id, $returnUrl, $refreshUrl);
    }
}
