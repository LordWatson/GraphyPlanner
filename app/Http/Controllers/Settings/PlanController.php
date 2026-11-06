<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\SyncSubscriptionAction;
use App\Enums\Feature;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StartCheckoutRequest;
use App\Services\AI\AiUsageGuard;
use App\Services\Plans\BillingGate;
use App\Services\Plans\PlanUsage;
use App\Support\Plans\Plan;
use App\Support\Plans\PlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Step CG-2.5 / B-9.3 — the self-serve plan page: current plan, this month's usage meters, and the
 * upgrade / manage-billing actions (Stripe Checkout + Customer Portal via Cashier). Owner-only;
 * billing-sensitive roles never reach it. When billing is switched off (the super-admin gate) or a
 * plan has no real Stripe price yet, the page still renders but the upgrade buttons explain that
 * checkout isn't available, never erroring.
 */
class PlanController extends Controller
{
    public function edit(
        Request $request,
        PlanCatalog $catalog,
        BillingGate $billingGate,
        AiUsageGuard $usage,
        PlanUsage $planUsage,
    ): Response {
        $user = $request->user();
        $organization = $user->organization;

        abort_unless($user->role === Role::Owner, 403);
        abort_if($organization === null, 404);

        $currency = strtolower($organization->default_currency) === 'gbp' ? 'gbp' : 'usd';
        $plan = $organization->currentPlan();
        $billingEnabled = $billingGate->enabledForOrg($organization);
        $subscription = $organization->subscription();

        $accountsUsed = $planUsage->socialAccounts($organization);
        $postsUsed = $planUsage->scheduledPostsThisMonth($organization);
        $credits = $usage->summary($organization)['credits'];

        return Inertia::render('settings/plan', [
            'currency' => $currency,
            'billingEnabled' => $billingEnabled,
            'current' => [
                ...$plan->toArray([$currency]),
                'on_trial' => $organization->onTrial(),
                'trial_ends_at' => $organization->trial_ends_at?->toIso8601String(),
                'subscribed' => $subscription !== null && $subscription->valid(),
                'on_grace_period' => $subscription?->onGracePeriod() ?? false,
                'stripe_status' => $subscription?->stripe_status,
                'ends_at' => $subscription?->ends_at?->toIso8601String(),
            ],
            'usage' => [
                ['feature' => Feature::SocialAccounts->value, 'label' => Feature::SocialAccounts->label(), 'used' => $accountsUsed, 'limit' => $plan->limit(Feature::SocialAccounts)],
                ['feature' => Feature::ScheduledPostsPerMonth->value, 'label' => Feature::ScheduledPostsPerMonth->label(), 'used' => $postsUsed, 'limit' => $plan->limit(Feature::ScheduledPostsPerMonth)],
                ['feature' => Feature::AiTextCredits->value, 'label' => Feature::AiTextCredits->label(), 'used' => $credits['used'], 'limit' => $credits['allowance']],
            ],
            'plans' => array_map(
                fn (Plan $p) => [
                    ...$p->toArray([$currency]),
                    'is_current' => $p->key === $plan->key,
                    'has_price' => $p->stripePriceId($currency, 'monthly') !== null,
                ],
                array_values(array_filter($catalog->public(), fn (Plan $p) => $p->segment() !== 'agency')),
            ),
            'annualDiscountLabel' => (string) config('plans.annual_discount_label'),
            'can' => [
                'manage_billing' => $billingEnabled && $organization->hasStripeId(),
            ],
        ]);
    }

    public function checkout(
        StartCheckoutRequest $request,
        PlanCatalog $catalog,
        BillingGate $billingGate,
    ): HttpResponse {
        $user = $request->user();
        $organization = $user->organization;

        abort_unless($user->role === Role::Owner, 403);
        abort_if($organization === null, 404);

        if (! $billingGate->enabledForOrg($organization)) {
            return back()->with('toast', ['type' => 'info', 'message' => __('Billing isn’t available yet — hang tight.')]);
        }

        $plan = $catalog->get($request->validated('plan'));
        $currency = $request->validated('currency');
        $interval = $request->validated('interval');
        $priceId = $plan->stripePriceId($currency, $interval);

        if ($priceId === null) {
            return back()->with('toast', ['type' => 'info', 'message' => __(':plan isn’t available for checkout yet.', ['plan' => $plan->name()])]);
        }

        $checkout = $organization
            ->newSubscription('default', $priceId)
            ->trialDays($plan->trialDays())
            ->checkout([
                'success_url' => route('plan.success'),
                'cancel_url' => route('plan.edit').'?checkout=cancelled',
            ]);

        // getTargetUrl() is the typed Stripe hosted-checkout URL; Inertia::location does a full-page
        // visit to it (a plain redirect would be swallowed by Inertia's XHR).
        return Inertia::location($checkout->redirect()->getTargetUrl());
    }

    public function portal(Request $request, BillingGate $billingGate): HttpResponse
    {
        $user = $request->user();
        $organization = $user->organization;

        abort_unless($user->role === Role::Owner, 403);
        abort_if($organization === null, 404);

        if (! $billingGate->enabledForOrg($organization) || ! $organization->hasStripeId()) {
            return back()->with('toast', ['type' => 'info', 'message' => __('There’s no billing account to manage yet.')]);
        }

        return Inertia::location($organization->billingPortalUrl(route('plan.edit')));
    }

    /**
     * Stripe redirects back here after checkout; reconcile the plan immediately rather than wait for
     * the webhook, so the page reflects the new plan on first load.
     */
    public function success(Request $request, SyncSubscriptionAction $sync): RedirectResponse
    {
        $organization = $request->user()->organization;

        if ($organization !== null) {
            $sync($organization->refresh());
        }

        return to_route('plan.edit')->with('toast', ['type' => 'success', 'message' => __('You’re all set — welcome to your new plan.')]);
    }
}
