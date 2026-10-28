<?php

namespace App\Providers;

use App\Listeners\SyncSubscriptionOnStripeWebhook;
use App\Models\Organization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Phase CG-2 — wires Laravel Cashier to the Organization billable (its subscriptions use an
 * `organization_id` owner key, so the customer model must be Organization) and keeps
 * `organizations.plan` reconciled with Stripe after every webhook.
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

    public function boot(): void
    {
        Event::listen(WebhookHandled::class, SyncSubscriptionOnStripeWebhook::class);
    }
}
