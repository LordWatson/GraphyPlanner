<?php

namespace App\Http\Requests\Settings;

use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Support\Plans\Plan;
use App\Support\Plans\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-2.4 — validates a Stripe Checkout request: a known public plan the org's workspace type is offered, a supported
 * currency and interval. Owner-only (billing).
 */
class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role === Role::Owner && $user->org_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Step GTM-5.1: only the paid plans this org's workspace type is offered — an agency can buy
        // the agency tiers and nothing else, and vice versa.
        $type = $this->user()?->organization->type ?? OrganizationType::Business;

        $checkoutPlans = array_values(array_map(
            fn (Plan $plan) => $plan->key,
            array_filter(app(PlanCatalog::class)->publicFor($type), fn (Plan $plan) => $plan->isPaid()),
        ));

        return [
            'plan' => ['required', 'string', Rule::in($checkoutPlans)],
            'currency' => ['required', 'string', Rule::in(['gbp', 'usd'])],
            'interval' => ['required', 'string', Rule::in(['monthly', 'annual'])],
        ];
    }
}
