<?php

namespace App\Providers;

use App\Listeners\SyncSubscriptionOnStripeWebhook;
use App\Models\Organization;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Phase CG-2 — wires Laravel Cashier to the Organization billable (its subscriptions use an
 * `organization_id` owner key, so the customer model must be Organization) and keeps
 * `organizations.plan` reconciled with Stripe after every webhook — the latter via
 * {@see SyncSubscriptionOnStripeWebhook}, which auto-discovery registers.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The billable is the Organization (its subscriptions use an `organization_id` owner key).
        // Cashier only *publishes* its own migrations (it never auto-loads them), so our bespoke
        // organization-keyed subscriptions/subscription_items tables are the only ones that run.
        Cashier::useCustomerModel(Organization::class);
    }

    // No boot(): `SyncSubscriptionOnStripeWebhook` is picked up by Laravel's listener
    // auto-discovery (see `bootstrap/cache/events.php`). Registering it here as well ran the plan
    // sync twice for every billing webhook — the second pass logging "Skipping already-processed".
}
