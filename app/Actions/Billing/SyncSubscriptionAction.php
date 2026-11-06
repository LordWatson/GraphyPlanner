<?php

namespace App\Actions\Billing;

use App\Models\Organization;
use App\Support\Plans\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-2.4 / B-9.3 — reconciles `organizations.plan` with the org's Stripe subscription. Called
 * after every relevant webhook (and on-demand after checkout) so the plan the app enforces always
 * reflects what the customer is actually paying for.
 *
 * Idempotent: it derives the plan purely from the current subscription state, so running it twice (a
 * replayed webhook, a double checkout return) lands on the same plan. A valid subscription maps its
 * Stripe price back to a plan key; no valid subscription falls back to the org type's default (free).
 */
class SyncSubscriptionAction
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function __invoke(Organization $organization): Organization
    {
        return DB::transaction(function () use ($organization) {
            $subscription = $organization->subscription();

            $target = null;

            if ($subscription !== null && $subscription->valid()) {
                // Cashier's subscription columns aren't typed for static analysis, so read them
                // through getAttribute() and narrow to a string at runtime.
                $priceValue = $subscription->getAttribute('stripe_price');
                $priceId = is_string($priceValue) ? $priceValue : null;

                if ($priceId === null) {
                    $itemPrice = $subscription->items()->first()?->getAttribute('stripe_price');
                    $priceId = is_string($itemPrice) ? $itemPrice : null;
                }

                $match = $priceId !== null ? $this->catalog->findByStripePriceId($priceId) : null;

                if ($match === null) {
                    // A paid, valid subscription on a price we don't recognise — don't silently
                    // downgrade; keep the current plan and flag it for a human.
                    Log::warning('Active subscription on an unmapped Stripe price; leaving plan unchanged', [
                        'org_id' => $organization->id,
                        'stripe_price' => $priceId,
                        'plan' => $organization->plan,
                    ]);

                    return $organization;
                }

                $target = $match['plan']->key;
            }

            $target ??= $this->catalog->defaultKeyFor($organization->type);

            if ($organization->plan !== $target) {
                $from = $organization->plan;
                $organization->plan = $target;
                $organization->save();

                Log::info('Subscription synced to plan', [
                    'org_id' => $organization->id,
                    'from' => $from,
                    'to' => $target,
                ]);
            }

            return $organization;
        });
    }
}
